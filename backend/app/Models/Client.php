<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class Client extends Model
{
    use HasFactory, HasUuids;

    // `created_at` + `updated_at` : Eloquent maintient `updated_at` à chaque
    // modification (colonnes `timestamp` gérées par le modèle, pas par la DB).

    protected $table = 'clients';

    // ------------------------------------------------------------------
    // Statuts du client (docs/models.puml)
    // ------------------------------------------------------------------

    /** Disponible à la réservation (scopeAvailable). */
    public const STATUS_AVAILABLE = 'AVAILABLE';

    /** Réservé par un employé : appel en cours. */
    public const STATUS_RESERVED = 'RESERVED';

    /**
     * DÉRIVÉ — affichage uniquement, **jamais stocké** : la dernière
     * réservation est un BV_VOICEMAIL ou un CALL_BACK.
     */
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    /** Indisponible temporairement pour tous (NO : 3 mois / 3e BV : 21 j). */
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';

    /** Liste noire (`is_blacklisted = true`). */
    public const STATUS_BLACKLISTED = 'BLACKLISTED';

    /** Confirmé (issue YES) — définitif jusqu'à clôture admin. */
    public const STATUS_CONFIRMED = 'CONFIRMED';

    /** Valeurs stockées dans `clients.status` (IN_PROGRESS en est exclu). */
    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_RESERVED,
        self::STATUS_UNAVAILABLE,
        self::STATUS_BLACKLISTED,
        self::STATUS_CONFIRMED,
    ];

    protected $fillable = [
        'name',
        'enterprise_name',
        'categories',
        'status',
        // Pointeur « réservation courante » : écrit uniquement par
        // `syncCurrentReservation()` (jamais par un update applicatif).
        'current_reservation_id',
        'current_comercial_id',
        'is_blacklisted',
        'returned_at',
        'licence_number',
        'licence_propre',
        'licence_propre_numero',
        'intervenant_name',
        'licence_status',
        'neq',
        'full_address',
        'municipality',
        'administrative_region',
        'phone',
        'email',
        'respondent_count',
        'respondents',
        'sub_category_count',
        'authorized_categories',
        'surety_company',
        'cautionnement_compagnie',
        'surety_amount',
        'licence_start_date',
        'licence_end_date',
        'representative_name',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'is_blacklisted' => 'boolean',
            'licence_propre' => 'boolean',
            'licence_propre_numero' => 'integer',
            'respondents' => 'array',
            'authorized_categories' => 'array',
            'cautionnement_compagnie' => 'array',
            'surety_amount' => 'decimal:2',
            'licence_start_date' => 'date',
            'licence_end_date' => 'date',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * Payload n8n (clés d'affichage en français) → colonnes `clients` en
     * snake_case. Seule porte d'entrée des données de licence : aucune clé
     * française n'est écrite telle quelle dans la base.
     *
     * Exclues volontairement — état applicatif que le payload ne doit jamais
     * repousser : `status`, `is_blacklisted`, `returned_at` et la réservation
     * du commercial (cf. docs/RULES.md §12).
     */
    public const PAYLOAD_MAP = [
        'Licence' => 'licence_number',
        'Licence (propre)' => 'licence_propre_numero',
        "Nom de l'intervenant / Entreprise" => 'enterprise_name',
        'Statut de la licence' => 'licence_status',
        'NEQ' => 'neq',
        'Adresse complète' => 'full_address',
        'Municipalité' => 'municipality',
        'Région administrative' => 'administrative_region',
        'Téléphone' => 'phone',
        'Courriel' => 'email',
        'Nombre de répondants' => 'respondent_count',
        'Répondants / Interlocuteurs (Qualifications)' => 'respondents',
        'Nombre de sous-catégories' => 'sub_category_count',
        'Catégories et sous-catégories autorisées' => 'authorized_categories',
        'Cautionnement (Compagnie / Association)' => 'surety_company',
        'Montant de la caution ($)' => 'surety_amount',
        'Date de début / délivrance' => 'licence_start_date',
        'Date de fin / paiement annuel' => 'licence_end_date',
    ];

    /**
     * Normalise un payload n8n en attributs `clients` prêts pour
     * create()/update() : clés ramenées aux colonnes snake_case (sans tenir
     * compte de la casse ni de la ponctuation — `l'intervenant` et
     * `l’intervenant` donnent la même colonne), chaînes vides → NULL et types
     * alignés sur les casts du modèle.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromPayload(array $payload): array
    {
        $columns = [];
        foreach (self::PAYLOAD_MAP as $payloadKey => $column) {
            $columns[self::normalizePayloadKey($payloadKey)] = $column;
        }

        $attributes = [];
        foreach ($payload as $key => $value) {
            $column = $columns[self::normalizePayloadKey((string) $key)] ?? null;

            if ($column === null) {
                continue; // clé inconnue ou état applicatif : ignorée
            }

            $attributes[$column] = self::castPayloadValue($column, $value);
        }

        // Le payload ne porte qu'un seul nom (« Nom de l'intervenant /
        // Entreprise ») : `enterprise_name` fait foi, `name` (affichage) et
        // `intervenant_name` en héritent pour ne pas rester vides côté UI.
        if (isset($attributes['enterprise_name'])) {
            $attributes['name'] ??= $attributes['enterprise_name'];
            $attributes['intervenant_name'] ??= $attributes['enterprise_name'];
        }

        return $attributes;
    }

    /**
     * Import d'un enregistrement scraper / webhook : **crée ou met à jour** le
     * client identifié par sa licence (docs/RULES.md §12).
     *
     *  - **clé d'upsert** : `licence_number` ; en repli
     *    `licence_propre_numero` (index `UNIQUE`) quand le payload ne porte
     *    pas de numéro de licence classique ;
     *  - **jamais d'état applicatif** : `status`, `is_blacklisted`,
     *    `returned_at` et la réservation sont exclus de `PAYLOAD_MAP` — à la
     *    création, valeurs neuves `AVAILABLE` / non blacklisté /
     *    `returned_at = NULL` ;
     *  - une ligne **identique** n'est pas réécrite (`unchanged`) :
     *    `updated_at` ne bouge donc pas d'une resynchronisation à l'autre ;
     *  - dérivations identiques à `ClientsFromJsonSeeder` : `categories` ←
     *    `authorized_categories`, `licence_propre` ← numéro propre renseigné.
     *
     * @return string `created` | `updated` | `unchanged`
     *
     * @throws InvalidArgumentException si aucune clé d'upsert n'est fournie
     */
    public static function upsertFromScraperPayload(array $payload): string
    {
        $attributes = self::attributesFromPayload($payload);

        $licenceNumber = $attributes['licence_number'] ?? null;
        $licencePropre = $attributes['licence_propre_numero'] ?? null;

        if (($licenceNumber === null || $licenceNumber === '') && $licencePropre === null) {
            throw new InvalidArgumentException(
                'Clé d\'upsert manquante : « Licence » (ou « Licence (propre) » en repli).'
            );
        }

        // Dérivations (mêmes conventions que l'import JSON, §12).
        if (array_key_exists('authorized_categories', $attributes)) {
            $attributes['categories'] = $attributes['authorized_categories'] ?? [];
        }

        if (array_key_exists('licence_propre_numero', $attributes)) {
            $attributes['licence_propre'] = $licencePropre !== null;
        }

        $client = self::query()
            ->where(
                ($licenceNumber === null || $licenceNumber === '')
                    ? ['licence_propre_numero' => $licencePropre]
                    : ['licence_number' => $licenceNumber]
            )
            ->first();

        if ($client === null) {
            self::create(array_merge(
                ['categories' => []],
                $attributes,
                // État applicatif : toujours neuf à la création, quel que
                // soit ce que le payload aurait pu tenter d'envoyer.
                ['status' => self::STATUS_AVAILABLE, 'is_blacklisted' => false, 'returned_at' => null]
            ));

            return 'created';
        }

        $client->fill($attributes);

        if (! $client->isDirty()) {
            return 'unchanged';
        }

        $client->save();

        return 'updated';
    }

    /**
     * Import **en masse** du webhook public `POST clients/bulk-upsert`
     * (docs/RULES.md §12).
     *
     * Chaque enregistrement est traité **dans sa propre transaction** : une
     * ligne invalide (ou un conflit d'unicité) est comptée dans `failed` et
     * détaillée dans `errors`, sans annuler le reste du lot — l'import partiel
     * reste exploitable côté scraper.
     *
     * @param  list<mixed>  $payloads
     * @return array{received:int, processed:int, created:int, updated:int, unchanged:int, failed:int, errors:list<array{index:int, licence_number:?string, error:string}>}
     */
    public static function bulkUpsertFromScraperPayload(array $payloads): array
    {
        $result = [
            'received' => count($payloads),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach (array_values($payloads) as $index => $payload) {
            try {
                if (! is_array($payload)) {
                    throw new InvalidArgumentException('Enregistrement attendu : un objet JSON par ligne.');
                }

                $outcome = DB::transaction(fn () => self::upsertFromScraperPayload($payload));

                $result[$outcome] += 1;
                $result['processed'] += 1;
            } catch (QueryException $e) {
                // Violation d'unicité (ex. `licence_propre_numero` déjà pris
                // par un autre client) : le SQL n'est jamais exposé au
                // client du webhook, il est journalisé côté serveur.
                Log::warning('Import public de clients : conflit de données.', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($payload),
                    'error' => 'Conflit de données : numéro de licence déjà utilisé par un autre client.',
                ];
            } catch (Throwable $e) {
                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($payload),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /** Clé d'upsert d'un item brut (rapport d'erreurs du webhook). */
    private static function payloadLicenceNumber(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['licence_number', 'Licence', ''] as $key) {
            $value = $payload[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Suppression **en masse** du webhook public `POST|DELETE
     * clients/bulk-delete` (docs/RULES.md §12).
     *
     * Garantie centrale : **un client qui porte des données liées n'est pas
     * supprimé.** Les tables enfants (`reservations`, `notes`, `rappels`)
     * sont toutes en `cascadeOnDelete` — supprimer le client effacerait son
     * historique. L'entrée est alors comptée dans `skipped`, détaillée dans
     * `skipped_items` avec le décompte des liens, et **la boucle passe au
     * client suivant** : un lot ne supprime que les clients vierges.
     *
     * Formes d'item acceptées : une chaîne (numéro de licence) **ou** un
     * objet payload (clés françaises, repli `licence_propre_numero`) —
     * mêmes formes que l'import.
     *
     * @param  list<mixed>  $items
     * @return array{received:int, processed:int, deleted:int, skipped:int, missing:int, failed:int, skipped_items:list<array{index:int, licence_number:?string, linked:array{reservations:int, notes:int, rappels:int}}>, missing_items:list<array{index:int, licence_number:?string}>, errors:list<array{index:int, licence_number:?string, error:string}>}
     */
    public static function bulkDeleteFromScraper(array $items): array
    {
        $result = [
            'received' => count($items),
            'processed' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'missing' => 0,
            'failed' => 0,
            'skipped_items' => [],
            'missing_items' => [],
            'errors' => [],
        ];

        foreach (array_values($items) as $index => $item) {
            try {
                $key = self::scraperLookupKey($item);

                if ($key === []) {
                    throw new InvalidArgumentException('Clé manquante : « Licence » (ou « Licence (propre) »).');
                }

                $licence = isset($key['licence_number'])
                    ? (string) $key['licence_number']
                    : (string) $key['licence_propre_numero'];

                // Transaction par ligne : la vérification des liens et la
                // suppression forment un seul geste (aucun client supprimé
                // pendant qu'une réservation viendrait d'être créée).
                $step = DB::transaction(function () use ($key, $licence): array {
                    $client = self::query()
                        ->where($key)
                        ->lockForUpdate()
                        ->withCount(['reservations', 'notes', 'rappels'])
                        ->first();

                    if ($client === null) {
                        return ['outcome' => 'missing'];
                    }

                    $linked = [
                        'reservations' => (int) $client->reservations_count,
                        'notes' => (int) $client->notes_count,
                        'rappels' => (int) $client->rappels_count,
                    ];

                    if (array_sum($linked) > 0) {
                        // Données liées : on **ignore** cette suppression et
                        // on passe au client suivant.
                        return [
                            'outcome' => 'skipped',
                            'licence_number' => $client->licence_number !== null && $client->licence_number !== ''
                                ? $client->licence_number
                                : $licence,
                            'linked' => $linked,
                        ];
                    }

                    // Suppression Eloquent (pas la requête brute) : le
                    // événement `deleted` invalide les listes distinctes.
                    $client->delete();

                    return ['outcome' => 'deleted'];
                });

                $result['processed'] += 1;

                if ($step['outcome'] === 'deleted') {
                    $result['deleted'] += 1;
                } elseif ($step['outcome'] === 'skipped') {
                    $result['skipped'] += 1;
                    $result['skipped_items'][] = [
                        'index' => $index,
                        'licence_number' => $step['licence_number'],
                        'linked' => $step['linked'],
                    ];
                } else {
                    $result['missing'] += 1;
                    $result['missing_items'][] = ['index' => $index, 'licence_number' => $licence];
                }
            } catch (QueryException $e) {
                Log::warning('Suppression publique de clients : conflit de données.', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($item),
                    'error' => 'Conflit de données : suppression impossible.',
                ];
            } catch (Throwable $e) {
                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($item),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Clé d'identification d'un item de suppression : chaîne nue → numéro de
     * licence, objet payload → `licence_number` puis repli
     * `licence_propre_numero` (mêmes règles que l'import).
     *
     * @return array<string, mixed> vide si aucune clé n'est fournie
     */
    private static function scraperLookupKey(mixed $item): array
    {
        if (is_scalar($item)) {
            $licence = trim((string) $item);

            return $licence === '' ? [] : ['licence_number' => $licence];
        }

        if (! is_array($item)) {
            return [];
        }

        $attributes = self::attributesFromPayload($item);

        $licence = $attributes['licence_number'] ?? null;

        if (is_string($licence) && $licence !== '') {
            return ['licence_number' => $licence];
        }

        if (($attributes['licence_propre_numero'] ?? null) !== null) {
            return ['licence_propre_numero' => $attributes['licence_propre_numero']];
        }

        return [];
    }

    /** Clé payload → forme normalisée (minuscules, sans ponctuation). */
    private static function normalizePayloadKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($key)) ?? '';
    }

    /** Alignement d'une valeur de payload sur le cast de la colonne cible. */
    private static function castPayloadValue(string $column, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return match ($column) {
            'licence_propre_numero', 'respondent_count', 'sub_category_count' => (int) $value,
            'surety_amount' => is_numeric($value) ? (float) $value : null,
            'licence_start_date', 'licence_end_date' => self::payloadDate($value),
            'respondents', 'authorized_categories' => self::payloadArray($value),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /** Date ISO n8n (`2025-05-28T00:00:00`) → `Y-m-d`, illisible → NULL. */
    private static function payloadDate(mixed $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** Tableau JSON n8n → tableau PHP (chaîne brute = un seul élément). */
    private static function payloadArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [$value];
        }

        return [];
    }

    /**
     * Clients réellement disponibles : le statut AVAILABLE prime, et un
     * returned_at passé ne bloque plus (réactivation prise en charge même
     * si le cron horaire n'a pas encore tourné).
     */
    public function scopeAvailable($query)
    {
        return $query
            ->where('status', self::STATUS_AVAILABLE)
            ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '<=', now()));
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function activeReservation()
    {
        return $this->hasOne(Reservation::class)->latest('created_at');
    }

    /**
     * Dernière réservation du client (colonne triée par date, puis id).
     * Préchargée par `Client::loadLatestReservations()` dans les listes.
     */
    public function latestReservation()
    {
        return $this->hasOne(Reservation::class, 'client_id')->latestOfMany('created_at', 'id');
    }

    /**
     * Réservation **courante** (pointeur dénormalisé, cf. migration
     * `2026_09_30_000002`) : la dernière réservation, jointure directe au
     * lieu d'une sous-requête. `current_reservation_id` en encode le statut ;
     * `current_comercial_id` porte l'employé qui la détient (visibilité du
     * téléphone, filtres « par commercial »).
     */
    public function currentReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'current_reservation_id');
    }

    /** Employé détenteur de la réservation courante (NULL sans réservation). */
    public function currentComercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_comercial_id');
    }

    /**
     * Recalcule le pointeur « réservation courante » du client à partir de
     * `reservations`.
     *
     * **Seul chemin d'écriture** de `current_reservation_id` /
     * `current_comercial_id` : `Reservation` l'appelle à chaque `saved` et
     * `deleted` (création, issue d'appel, suppression), et
     * `AdminController::debloquerClient()` l'appelle après sa suppression
     * massique — la seule écriture qui contourne Eloquent. Le pointeur ne
     * peut donc pas diverger de la réalité.
     *
     * Écriture en query builder : aucun événement de modèle, et surtout pas
     * d'`updated_at` — une réservation qui évolue ne doit pas reclasser son
     * client dans les listes triées « mis à jour le ».
     */
    public function syncCurrentReservation(): void
    {
        // Même ordre que `latestReservation()` : created_at, puis id, décroissants.
        $latest = $this->reservations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'client_id', 'comercial_id', 'status', 'created_at']);

        DB::table($this->getTable())
            ->where('id', $this->id)
            ->update([
                'current_reservation_id' => $latest?->id,
                'current_comercial_id' => $latest?->comercial_id,
            ]);

        // Miroir en mémoire : les écritures qui suivent dans la même requête
        // (payload, visibilité du téléphone) voient la bonne valeur sans
        // recharger la ligne, sans marquer le modèle comme modifié.
        $this->forceFill([
            'current_reservation_id' => $latest?->id,
            'current_comercial_id' => $latest?->comercial_id,
        ]);
        $this->syncOriginalAttributes(['current_reservation_id', 'current_comercial_id']);
        $this->setRelation('currentReservation', $latest);
    }

    // ------------------------------------------------------------------
    // Statut AFFICHÉ (jamais stocké, jamais utilisé pour filtrer).
    //
    // Un client réservé est qualifié par sa dernière réservation : le modèle
    // ne connaît qu'une seule valeur dérivée, `STATUS_IN_PROGRESS`
    // (BV_VOICEMAIL comme CALL_BACK affichent « En cours de traitement »).
    // ------------------------------------------------------------------

    /**
     * Statut « reformulé » d'un client, pour l'affichage dans les listes.
     *
     * Règle métier (docs/RULES.md §2) — un client encore **RESERVED** est
     * qualifié par l'état de sa **dernière réservation** :
     *
     *  - YES        -> CONFIRMED   « Confirmé »
     *  - NO         -> UNAVAILABLE « Non disponible » (+ retour)
     *  - BV_VOICEMAIL / CALL_BACK -> IN_PROGRESS « En cours de traitement »
     *  - sinon (PENDING, aucune)  -> son statut courant (« Réservé »)
     *
     * Tous les autres statuts (AVAILABLE, CONFIRMED, UNAVAILABLE,
     * BLACKLISTED…) sont retournés tels quels. `clients.status` brut n'est
     * **jamais modifié** : les filtres, les KPI et le workflow continuent de
     * le lire directement.
     *
     * 1 seule requête au maximum (si aucune réservation n'est préchargée) ;
     * passez par `loadLatestReservations()` pour une liste.
     */
    public function displayStatus(): string
    {
        if ($this->status !== self::STATUS_RESERVED) {
            return $this->status;
        }

        return match ($this->latestReservationStatus()) {
            Reservation::STATUS_YES => self::STATUS_CONFIRMED,
            Reservation::STATUS_NO => self::STATUS_UNAVAILABLE,
            Reservation::STATUS_BV_VOICEMAIL,
            Reservation::STATUS_CALL_BACK => self::STATUS_IN_PROGRESS,
            default => $this->status,
        };
    }

    /** Statut de la dernière réservation : relation préchargée, sinon requête unique. */
    private function latestReservationStatus(): ?string
    {
        if ($this->relationLoaded('latestReservation')) {
            return $this->latestReservation?->status;
        }

        if ($this->relationLoaded('reservations')) {
            return $this->reservations
                ->sortByDesc(fn (Reservation $r) => [$r->created_at, $r->id])
                ->first()?->status;
        }

        return $this->reservations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('status');
    }

    /**
     * Précharge la dernière réservation de tous les clients « RESERVED » /
     * « CONFIRMED » / « UNAVAILABLE » d'un lot en **une seule requête** (sinon
     * `displayStatus()` ferait une requête par ligne à formatter, la visibilité
     * du téléphone une seconde, et la colonne « Statut » — qui affiche le
     * statut de la **dernière réservation** hors `AVAILABLE` / liste noire —
     * une troisième).
     *
     * @param  iterable<int|string, mixed>  $clients
     */
    public static function loadLatestReservations(iterable $clients): void
    {
        $pending = collect($clients)
            ->filter(fn ($client) => $client instanceof self
                && in_array($client->status, [self::STATUS_RESERVED, self::STATUS_CONFIRMED, self::STATUS_UNAVAILABLE], true)
                && ! $client->relationLoaded('latestReservation'));

        $ids = $pending->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        $latest = [];
        $rows = Reservation::query()
            ->whereIn('client_id', $ids)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // `comercial_id` : permet de savoir qui détient la réservation
            // courante (visibilité du numéro de téléphone) sans requête de plus.
            ->get(['id', 'client_id', 'comercial_id', 'status', 'created_at']);

        foreach ($rows as $row) {
            // Déjà trié du plus récent au plus ancien : on garde le 1er vu.
            $latest[$row->client_id] ??= $row;
        }

        foreach ($pending as $client) {
            $client->setRelation('latestReservation', $latest[$client->id] ?? null);
        }
    }

    /**
     * Journal d'événements du client : issues d'appel (YES/NO/BV/CALL_BACK),
     * réservation (RESERVED), listes noires (BLACKLISTED / RETURNED_TO_AVAILABLE)
     * et commentaires libres (NOTE) — table unique `notes`.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** Rappels planifiés (table `rappels`, source de vérité des rappels). */
    public function rappels(): HasMany
    {
        return $this->hasMany(Rappel::class);
    }

    /**
     * Colonnes dont les valeurs distinctes sont exposées aux listes
     * déroulantes (`GET /categories`, `/municipalities`,
     * `/administrative-regions`). Liste blanche : le nom de colonne n'est
     * jamais repris d'une requête HTTP.
     */
    public const DISTINCT_COLUMNS = ['categories', 'municipality', 'administrative_region'];

    /** Cache des listes distinctes : 1 semaine (604 800 s). */
    public const DISTINCT_CACHE_TTL = 604800;

    /**
     * Valeurs distinctes d'une colonne, sans doublons, triées — alimente les
     * filtres de la liste des prospects. Lu via Eloquent : les casts du
     * modèle s'appliquent aux colonnes JSON, plus aucune requête DB::table().
     *
     * Résultat mis en cache une semaine ; il est invalidé à chaque
     * changement de clients (voir `forgetDistinctValues()`), donc les listes
     * se mettent à jour seules.
     *
     * @return list<string>
     */
    public static function distinctValues(string $column): array
    {
        if (! in_array($column, self::DISTINCT_COLUMNS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Colonne non exposée aux filtres : %s (attendu : %s)',
                $column,
                implode(', ', self::DISTINCT_COLUMNS)
            ));
        }

        return Cache::remember(
            self::distinctCacheKey($column),
            self::DISTINCT_CACHE_TTL,
            fn () => self::queryDistinctValues($column)
        );
    }

    /**
     * Invalide les listes distinctes mises en cache : appelée à chaque
     * création / mise à jour / suppression d'un client (événements du modèle)
     * et par `ClientsFromJsonSeeder` (suppressions en masse, qui ne
     * déclenchent pas ces événements). L'échéance hebdomadaire du cache ne
     * devient qu'un filet de sécurité.
     */
    public static function forgetDistinctValues(): void
    {
        foreach (self::DISTINCT_COLUMNS as $column) {
            Cache::forget(self::distinctCacheKey($column));
        }
    }

    /** Clé de cache d'une liste distincte. */
    private static function distinctCacheKey(string $column): string
    {
        return 'clients:distinct:'.$column;
    }

    /** Auto-invalidation du cache des listes distinctes. */
    protected static function booted(): void
    {
        static::saved(fn () => static::forgetDistinctValues());
        static::deleted(fn () => static::forgetDistinctValues());
    }

    /**
     * Requête réelle derrière `distinctValues()` (à ne pas appeler seule) :
     * `categories` est un tableau JSON éclaté en libellés, les autres colonnes
     * sont des chaînes (`SELECT DISTINCT`). Dans les deux cas, valeurs vides
     * écartées, doublons supprimés même à la casse près, puis tri.
     */
    private static function queryDistinctValues(string $column): array
    {
        $values = static::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column);

        return $values
            ->flatMap(function ($raw) use ($column) {
                // `categories` : le pluck Eloquent renvoie déjà des tableaux
                // décodés (cast `array`), on les éclate en libellés.
                if ($column === 'categories') {
                    $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

                    return is_array($decoded) ? $decoded : [$raw];
                }

                return [$raw];
            })
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            // Déduplication insensible à la casse : « Montréal » et
            // « montréal » ne doivent pas revenir en double.
            ->unique(fn ($value) => mb_strtolower(trim($value)))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Options des filtres des deux listes de prospects (GET /filters) : les
     * mêmes valeurs distinctes que GET /categories, GET /municipalities et
     * GET /administrative-regions, groupées en une seule réponse.
     *
     * Seule méthode du modèle qui n'est pas un scope : elle renvoie un
     * tableau d'agrégation, pas un Builder.
     *
     * @return array{categories: list<string>, municipalities: list<string>, administrative_regions: list<string>}
     */
    public static function getUniqueCategoriesAndMunicipalities(): array
    {
        return [
            'categories' => self::distinctValues('categories'),
            'municipalities' => self::distinctValues('municipality'),
            'administrative_regions' => self::distinctValues('administrative_region'),
        ];
    }

    /**
     * Filtre par municipalité (libellé exact), scope Eloquent chaînable :
     *   Client::query()->filterByMunicipalities(['Québec', 'Lévis'])
     *
     * Le filtre s'applique à la requête du modèle (casts, scopes globaux et
     * événements préservés) — plus de DB::table() ni de sous-requête
     * whereIn('id', …) qui doublonnait les requêtes.
     */
    public function scopeFilterByMunicipalities(Builder $query, string|array $municipalities): Builder
    {
        $values = self::nonEmptyValues($municipalities);

        return $values === [] ? $query : $query->whereIn('municipality', $values);
    }

    /**
     * Filtre par région administrative (libellé exact), scope Eloquent
     * chaînable, même mécanique que `filterByMunicipalities()` :
     *   Client::query()->filterByAdministrativeRegions('Lanaudière')
     */
    public function scopeFilterByAdministrativeRegions(Builder $query, string|array $regions): Builder
    {
        $values = self::nonEmptyValues($regions);

        return $values === [] ? $query : $query->whereIn('administrative_region', $values);
    }

    /**
     * Filtre par catégorie : contenance JSON (`categories` contient le
     * libellé), et non LIKE sur le JSON encodé. Plusieurs valeurs = OU.
     *
     * `whereJsonContains()` fait le travail côté moteur (JSON_CONTAINS sur
     * MySQL, json_each sur SQLite) : le libellé doit correspondre à un
     * élément exact du tableau — « Résidentiel » ne peut plus attraper un
     * libellé qui le contient par hasard.
     */
    public function scopeFilterByCategories(Builder $query, string|array $categories): Builder
    {
        $values = self::nonEmptyValues($categories);

        if ($values === []) {
            return $query;
        }

        return $query
            ->whereNotNull('categories')
            ->where('categories', '!=', '') // valeur non JSON : écartée avant la fonction
            ->where(function (Builder $nested) use ($values) {
                foreach ($values as $category) {
                    $nested->orWhereJsonContains('categories', $category);
                }
            });
    }

    /**
     * Filtre par **statut client** — un ou plusieurs statuts à la fois,
     * pour les badges « Tous + 4 statuts » de l'overview :
     *
     *   Client::query()->filterByStatuses('AVAILABLE,RESERVED')  // chaîne
     *   Client::query()->filterByStatuses(['AVAILABLE'])         // tableau
     *
     * Valeurs validées contre `Client::STATUSES` (une valeur inconnue est
     * ignorée), sans doublon ; vide / null = aucun filtre.
     *
     * Une ligne **blacklistée** appartient au seau `BLACKLISTED`, quel que
     * soit son `status` (une donnée réelle a encore `status = AVAILABLE`) :
     * elle n'entre donc que si `BLACKLISTED` est demandé. C'est la définition
     * **exacte** de `by_status` (`GET clients/overview`) — le chiffre affiché
     * sur un badge vaut alors le nombre de lignes renvoyées après clic.
     */
    public function scopeFilterByStatuses(Builder $query, string|array|null $statuses): Builder
    {
        $values = $statuses === null
            ? []
            : (is_array($statuses) ? $statuses : explode(',', (string) $statuses));

        $statuses = array_values(array_unique(array_intersect(
            array_map(fn ($value) => trim((string) $value), $values),
            self::STATUSES,
        )));

        if ($statuses === []) {
            return $query;
        }

        $others = array_values(array_diff($statuses, [self::STATUS_BLACKLISTED]));
        $hasBlacklist = in_array(self::STATUS_BLACKLISTED, $statuses, true);

        return $query->where(function (Builder $nested) use ($others, $hasBlacklist) {
            if ($others !== []) {
                $nested->where(function (Builder $group) use ($others) {
                    $group->whereIn('status', $others)
                        ->where('is_blacklisted', false);
                });
            }

            if ($hasBlacklist) {
                $others === []
                    ? $nested->where('is_blacklisted', true)
                    : $nested->orWhere('is_blacklisted', true);
            }
        });
    }

    /**
     * Filtre par **statut de réservation courante** (badges « Oui / Non /
     * BV / À rappeler / - » de la colonne « Statut », docs/RULES.md §9).
     *
     * Le statut affiché se déduit de `current_reservation_id` (pointer
     * maintenu par `syncCurrentReservation()`) : la valeur du badge et la
     * valeur filtrée proviennent donc de la **même** colonne — l'invariant
     * « compteur du badge = lignes rendues » tient par construction.
     *
     * Les deux dimensions de badges sont **disjoints** : un client `AVAILABLE`
     * (relisté) ou en liste noire affiche son statut **client**, jamais sa
     * réservation, et ce scope l'exclut donc toujours. Combiner
     * `filterByStatuses()` et `scopeFilterByReservationStatuses()` en `OR`
     * donne alors une union exacte.
     */
    public function scopeFilterByReservationStatuses(Builder $query, string|array|null $statuses): Builder
    {
        $values = $statuses === null
            ? []
            : (is_array($statuses) ? $statuses : explode(',', (string) $statuses));

        $statuses = array_values(array_unique(array_intersect(
            array_map(fn ($value) => trim((string) $value), $values),
            Reservation::STATUSES,
        )));

        if ($statuses === []) {
            return $query;
        }

        return $query
            ->where('is_blacklisted', false)
            ->where('status', '!=', self::STATUS_AVAILABLE)
            ->whereIn(
                'current_reservation_id',
                Reservation::query()->whereIn('status', $statuses)->select('id')
            );
    }

    /**
     * Recherche par sous-chaîne dans une colonne JSON (`respondents`,
     * `categories`, `authorized_categories`).
     *
     * `whereJsonContains()` exige une valeur exacte et `JSON_SEARCH()` est
     * exclusif à MySQL : LIKE sur la représentation textuelle reste la seule
     * option portable pour une recherche libre. C'est la seule expression SQL
     * brute du modèle, isolée dans ce scope.
     */
    public function scopeOrWhereJsonTextLike(Builder $query, string $column, string $like): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return $query->orWhereRaw('CAST('.$wrapped.' AS CHAR) LIKE ?', [$like]);
    }

    /**
     * Colonnes triables de la page « Prospects » (`GET /clients`) — la même
     * liste sert à la réservation d'un lot (`POST clients/reserver`).
     */
    public const SORTABLE = [
        'name' => 'name',
        'enterprise_name' => 'enterprise_name',
        'email' => 'email',
        'phone' => 'phone',
        'status' => 'status',
        'municipality' => 'municipality',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'licence_end_date' => 'licence_end_date',
    ];

    /**
     * Requête **partagée** de la page « Prospects » : filtres (scopes
     * Eloquent), exclusions d'affichage et tri.
     *
     * `GET /clients` **et** `POST clients/reserver` passent par ce scope :
     * le lot réservé est exactement celui que l'employé voit à l'écran, dans
     * le même ordre (docs/RULES.md §7 — « même filtre et même tri que la
     * page »). `page` / `per_page` sont ignorés : la réservation prend le
     * premier lot de candidats de cette liste.
     *
     * @param  array<string, mixed>  $input  Paramètres de requête de la page.
     */
    public function scopeProspectList(Builder $query, array $input = []): Builder
    {
        if ($municipality = $input['municipality'] ?? null) {
            $query->filterByMunicipalities($municipality);
        }

        if ($category = $input['category'] ?? null) {
            $query->filterByCategories($category);
        }

        if ($region = $input['administrative_region'] ?? null) {
            $query->filterByAdministrativeRegions($region);
        }

        if ($search = $input['search'] ?? null) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                    ->orWhere('enterprise_name', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like)
                    ->orWhere('phone', 'LIKE', $like)
                    ->orWhere('neq', 'LIKE', $like)
                    ->orWhere('municipality', 'LIKE', $like)
                    ->orWhere('licence_number', 'LIKE', $like)
                    ->orWhere('licence_propre_numero', 'LIKE', $like)
                  // Colonnes JSON : sous-chaîne via le scope dédié
                  // (voir Client::scopeOrWhereJsonTextLike).
                    ->orWhereJsonTextLike('respondents', $like)
                    ->orWhereJsonTextLike('categories', $like)
                    ->orWhereJsonTextLike('authorized_categories', $like);
            });
        }

        // Exclusions de la page : hors liste noire, réellement disponible,
        // aucune réservation active en cours (ni la sienne ni celle d'un
        // autre employé).
        $query->where('is_blacklisted', false)
            ->available()
            ->whereDoesntHave('reservations', fn ($q) => $q->active());

        // Même tri que la page (défaut : `created_at` desc).
        $sortBy = (string) ($input['sort_by'] ?? 'created_at');
        $sortOrder = strtolower((string) ($input['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (array_key_exists($sortBy, self::SORTABLE)) {
            $query->orderBy(self::SORTABLE[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        return $query;
    }

    /** Liste de filtre nettoyée : chaînes non vides uniquement. */
    private static function nonEmptyValues(string|array $values): array
    {
        return array_values(array_filter(
            (array) $values,
            fn ($value) => is_string($value) && trim($value) !== ''
        ));
    }
}
