<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Prospect / client : colonnes, statuts, relations, scopes simples et
 * utilitaires de normalisation (`cleanPhone()`, `normalizePhone()`,
 * `simpleName()`).
 *
 * Ce modèle a été allégé (refactor « Client ») — le détail métier vit
 * désormais ailleurs :
 *
 *  - `App\Services\Client\ClientImportService` : API **publique** n8n /
 *    scraper (upsert, suppression, liste noire, indisponibilité par
 *    téléphone, fausses réservations NON) avec ses `DB::transaction` ;
 *  - `App\Services\Client\ClientSearchService` : filtres, recherche et
 *    badges de la page « Prospects » / « Grande liste » ;
 *  - `App\Observers\ClientObserver` : colonnes dérivées `phone_normalized`
 *    et `simple_name`.
 */
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

    /**
     * Issue « Double » : le prospect reste **tenu** par l'employé qui a
     * enregistré l'issue — mêmes règles que `RESERVED` (réservation active,
     * visible dans la Grande liste **que par son titulaire**).
     */
    public const STATUS_DOUBLE = 'DOUBLE';

    /**
     * Issue « Info » : même régime que `DOUBLE` / `RESERVED` — le prospect
     * reste réservé par l'employé, hors du pool partagé.
     */
    public const STATUS_INFO = 'INFO';

    /**
     * Issue « Autre » : même régime que `DOUBLE` / `INFO` / `RESERVED` — le
     * prospect reste tenu par l'employé, hors du pool partagé (réservation
     * active, visible **que par son titulaire** dans la Grande liste).
     */
    public const STATUS_AUTRE = 'AUTRE';

    /**
     * Issue « Doublon » : le prospect est reconnu comme **répété** (même
     * téléphone normalisé qu'une fiche déjà présente). Statut **stocké** ; la
     * fiche d'origine est pointée par `parent_client_id`.
     */
    public const STATUS_REPETE = 'REPETE';

    /**
     * DÉRIVÉ — affichage et filtre uniquement, **jamais stocké** (même
     * régime que `IN_PROGRESS`) : le client n'a **aucun numéro** de
     * téléphone (`phone` NULL ou vide). Ce n'est pas un état du cycle de
     * vie : un client sans numéro reste `AVAILABLE` / `RESERVED` / etc.,
     * il entre en plus dans le seau `SANS_TELEPHONE` des badges.
     */
    public const STATUS_SANS_TELEPHONE = 'SANS_TELEPHONE';

    /** Valeurs stockées dans `clients.status` (IN_PROGRESS en est exclu). */
    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_RESERVED,
        self::STATUS_UNAVAILABLE,
        self::STATUS_BLACKLISTED,
        self::STATUS_CONFIRMED,
        self::STATUS_DOUBLE,
        self::STATUS_INFO,
        self::STATUS_AUTRE,
        self::STATUS_REPETE,
    ];

    /**
     * Statuts pour lesquels le prospect est **tenu** par un employé : le
     * régime « Réservé », auquel `DOUBLE` et `INFO` se conforment
     * (réservation active, numéro visible pour le titulaire, bouton
     * « Suite appel » actif).
     */
    public const HELD_STATUSES = [
        self::STATUS_RESERVED,
        self::STATUS_CONFIRMED,
        self::STATUS_DOUBLE,
        self::STATUS_INFO,
        self::STATUS_AUTRE,
    ];

    // ------------------------------------------------------------------
    // Source du prospect (origine — répertoire `sources`)
    // ------------------------------------------------------------------

    /**
     * Source par défaut : tout prospect créé sans source explicite
     * (colonnes `NOT NULL DEFAULT 'Affaire'`, import n8n sans clé
     * « Source ») porte « Affaire ». Même répertoire `sources` que
     * `enterprises.source` — sans rapport avec RingCentral.
     */
    public const DEFAULT_SOURCE = 'Affaire';

    protected $fillable = [
        'name',
        'enterprise_name',
        'categories',
        'status',
        // Pointeur « réservation courante » : écrit uniquement par
        // `syncCurrentReservation()` (jamais par un update applicatif).
        'current_reservation_id',
        'current_comercial_id',
        // Doublon : fiche d'origine quand ce prospect est un répété (statut
        // `REPETE`). Pointeur auto-référent `clients.id`, posé par la logique de
        // détection de doublons ; `null` = fiche autonome.
        'parent_client_id',
        'is_blacklisted',
        // Drapeau « fiche modifiée à la main » : positionné par l'interface
        // (saisie manuelle) et lu par l'import scraper / n8n, qui **ne
        // réécrit plus** la fiche quand il vaut `true`.
        'is_manually_updated',
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
        // Origine du prospect (répertoire `sources`) — valeur texte, pas de
        // clé étrangère : `Client::DEFAULT_SOURCE` s'applique à la création.
        'source',
        // Note personnalisée libre (modale « Modifier le client ») — texte
        // multi-lignes ; chaque retour à la ligne forme un élément de liste.
        'note_personnalise',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'is_blacklisted' => 'boolean',
            'is_manually_updated' => 'boolean',
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

    /** Trim + espaces répétés (dont insécables) réduits, quotes retirées. */
    public static function cleanText(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null; // NULL ou liste placée dans une colonne texte
        }

        $text = preg_replace('/[\x{00A0}\s]+/u', ' ', (string) $value) ?? '';
        $text = trim(trim($text), "\"'“”‘’«»");

        return $text === '' ? null : $text;
    }

    /**
     * Expression SQL « chiffres seuls » d'une expression de colonne :
     * retire espaces (dont insécable), tirets, parenthèses, points, `+` et
     * `/` — la ponctuation usuelle des numéros de téléphone.
     */
    public static function digitsOnlySql(string $expr): string
    {
        $separators = [' ', "\xC2\xA0", '-', '(', ')', '.', '+', '/'];

        foreach ($separators as $separator) {
            $expr = 'REPLACE(' . $expr . ", '" . $separator . "', '')";
        }

        return $expr;
    }

    /**
     * Téléphone nord-américain reformatté, extension conservée :
     * `5143535820 Ext.: 5417` → `514-353-5820 ext. 5417`.
     * Une valeur trop courte ou étrangère est laissée telle quelle.
     */
    public static function cleanPhone(mixed $value): ?string
    {
        $text = Client::cleanText($value);

        if ($text === null) {
            return null;
        }

        $extension = null;
        if (preg_match('/^(.*?)\s*(?:ext(?:ension)?\.?|poste|#)\s*[:.]?\s*(\d{1,8})\s*$/iu', $text, $m)) {
            $text = trim($m[1]);
            $extension = $m[2];
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        if (strlen($digits) === 10) {
            $text = sprintf('%s-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        } elseif (strlen($digits) === 11 && $digits[0] === '1') {
            $text = sprintf('1 %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
        }

        return $extension === null || $extension === ''
            ? ($text === '' ? null : $text)
            : sprintf('%s ext. %s', $text, $extension);
    }

    /**
     * Numéro de téléphone **détecté** : toutes les écritures d'un même
     * numéro sont ramenées à une clé unique de chiffres nationaux, si bien
     * que les formes ci-dessous sont reconnues comme un seul et même
     * numéro :
     *
     *   819-418-6550 · 8194186550 · (819) 418 6550 · 819.418.6550
     *   +1819-418-6550 · +18194186550 · +1-819-418-6550 · 1 819 418 6550
     *   → tous → `8194186550`
     *
     *  - l'**extension** est retirée (`819-418-6550 ext. 5417` → le numéro
     *    seul) : elle ne fait pas partie de l'identité du numéro ;
     *  - ponctuation, espaces (dont insécables) et `+` supprimés : chiffres
     *    seuls ;
     *  - l'indicatif nord-américain `1` (11 chiffres) retiré : la comparaison
     *    se fait sur le numéro **national**, la forme que stocke
     *    `cleanPhone()`.
     *
     * @return ?string clé de chiffres (`8194186550`), null si le champ ne
     *                 contient aucun chiffre (champ vide / illisible)
     */
    public static function normalizePhone(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null; // NULL ou liste placée dans un champ téléphone
        }

        $text = Client::cleanText($value);

        if ($text === null) {
            return null;
        }

        // Extension postérieure (`… Ext.: 5417`, `… poste 3`) : non retenue.
        if (preg_match('/^(.*?)\s*(?:ext(?:ension)?\.?|poste|#)\s*[:.]?\s*(\d{1,8})\s*$/iu', $text, $m)) {
            $text = trim($m[1]);
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        if ($digits === '') {
            return null;
        }

        // Indicatif pays nord-américain : `+1 819…` et `819…` sont le même
        // numéro (les codes région NANP ne commencent jamais par 1).
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Clé de nom **sans accent ni casse** : `José Tremblay` →
     * `jose tremblay`. Valeur stockée dans la colonne dérivée
     * `simple_name`, peuplée à la création et à chaque mise à jour par
     * `App\Observers\ClientObserver` (cf. migration
     * `add_search_normalization_to_clients_table`).
     *
     * `null` si le champ est vide — un nom NULL se distingue ainsi d'un nom
     * non vide mais réduit à rien par le nettoyage.
     */
    public static function simpleName(mixed $value): ?string
    {
        $text = self::cleanText($value);

        if ($text === null) {
            return null;
        }

        // `Str::ascii()` translittère accents et caractères composés
        // (`é` → `e`, `œ` → `oe`) : la comparaison devient indépendante de
        // la saisie, y compris `Œ` / `œ` / `OE`.
        $simple = Str::lower(Str::ascii($text));
        $simple = trim(preg_replace('/\s+/u', ' ', $simple) ?? '');

        return $simple === '' ? null : $simple;
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
            ->where(fn($q) => $q->whereNull('returned_at')->orWhere('returned_at', '<=', now()));
    }

    /**
     * Clients **actifs** sur une période `[début, fin)` : la fiche a été créée
     * sur la période, **ou** une réservation y a été créée, **ou** une trace
     * d'appel y a été posée (note d'issue `CALL_TYPES` ou note porteuse d'un
     * `call_log_id` — bouton « Appel » RingCentral).
     *
     * C'est la borne des compteurs de statut du tableau de bord quand le
     * filtre Année / Mois / Semaine / Jour est posé : « libre sur la période »
     * = actuellement libre **et** touché (ou créé) pendant la période.
     */
    public function scopeActiveBetween($query, $start, $end)
    {
        return $query->where(function ($q) use ($start, $end) {
            $q->where('created_at', '>=', $start)->where('created_at', '<', $end);

            $q->orWhereHas('reservations', function ($r) use ($start, $end) {
                $r->where('created_at', '>=', $start)->where('created_at', '<', $end);
            });

            $q->orWhereHas('notes', function ($n) use ($start, $end) {
                $n->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->where(function ($t) {
                        $t->calls()->orWhereNotNull('call_log_id');
                    });
            });
        });
    }

    /**
     * Clients **sans numéro** (`status` dérivé `SANS_TELEPHONE`) : `phone`
     * NULL ou chaîne vide — les deux formes ont existé en base.
     *
     * C'est le filtre du badge « Sans téléphone » de la Grande liste admin
     * et l'exclusion appliquée à la liste **commerciale** (un prospect sans
     * numéro ne peut pas être appelé).
     */
    public function scopeWithoutPhone($query)
    {
        return $query->where(fn($q) => $q->whereNull('phone')->orWhere('phone', ''));
    }

    /** Inverse de `scopeWithoutPhone()` — au moins un numéro renseigné. */
    public function scopeWithPhone($query)
    {
        return $query->whereNotNull('phone')->where('phone', '!=', '');
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
     * Fiche d'origine dont ce client est le **doublon** (`parent_client_id`,
     * statut `REPETE`). `null` = fiche autonome (aucun parent).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_client_id');
    }

    /** Doublons rattachés à cette fiche — l'inverse de `parent()`. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_client_id');
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
                ->sortByDesc(fn(Reservation $r) => [$r->created_at, $r->id])
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
            ->filter(fn($client) => $client instanceof self
                && in_array($client->status, [self::STATUS_RESERVED, self::STATUS_CONFIRMED, self::STATUS_UNAVAILABLE], true)
                && !$client->relationLoaded('latestReservation'));

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
     * `/administrative-regions`, `/clients/sources`). Liste blanche : le
     * nom de colonne n'est jamais repris d'une requête HTTP.
     */
    public const DISTINCT_COLUMNS = ['categories', 'municipality', 'administrative_region', 'source'];

    /** Cache des listes distinctes : 1 semaine (604 800 s). */
    public const DISTINCT_CACHE_TTL = 604800;

    /**
     * Clé du compteur de version du cache des listes distinctes. Les listes
     * étant cachées par (colonne, source), on ne peut pas énumérer les clés à
     * invalider : `forgetDistinctValues()` pousse ce compteur, intégré à
     * chaque clé, ce qui rend toutes les variantes obsolètes d'un coup.
     */
    private const DISTINCT_VERSION_KEY = 'clients:distinct:__version';

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
    public static function distinctValues(string $column, ?string $source = null): array
    {
        if (!in_array($column, self::DISTINCT_COLUMNS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Colonne non exposée aux filtres : %s (attendu : %s)',
                $column,
                implode(', ', self::DISTINCT_COLUMNS)
            ));
        }

        return Cache::remember(
            self::distinctCacheKey($column, $source),
            self::DISTINCT_CACHE_TTL,
            fn() => self::queryDistinctValues($column, $source)
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
        // Les listes sont cachées par (colonne, source) : on ne peut pas
        // énumérer les clés. On **pousse le compteur de version** intégré à
        // chaque clé — toutes les variantes (toutes sources) tombent obsolètes
        // d'un coup ; les entrées orphelines sont purgées par le TTL.
        if (! Cache::has(self::DISTINCT_VERSION_KEY)) {
            Cache::forever(self::DISTINCT_VERSION_KEY, 1);

            return;
        }

        Cache::increment(self::DISTINCT_VERSION_KEY);
    }

    /** Version courante du cache des listes distinctes (défaut : 1). */
    private static function distinctVersion(): int
    {
        return (int) Cache::rememberForever(self::DISTINCT_VERSION_KEY, fn() => 1);
    }

    /**
     * Clé de cache d'une liste distincte, scopée par source et versionnée.
     * `source` vide / null = toutes les sources (onglet « Tous »).
     */
    private static function distinctCacheKey(string $column, ?string $source = null): string
    {
        $scope = ($source === null || $source === '') ? 'all' : $source;

        return sprintf('clients:distinct:%s:s:%s:v%d', $column, $scope, self::distinctVersion());
    }

    /** Auto-invalidation du cache des listes distinctes. */
    protected static function booted(): void
    {
        static::saved(fn() => static::forgetDistinctValues());
        static::deleted(fn() => static::forgetDistinctValues());
    }

    /**
     * Libellés **atomiques** d'un tableau `categories` : certains payloads
     * RBQ/n8n fusionnent plusieurs catégories en un seul élément séparé par
     * « , » *précédé d'espace* — « Bâtiments Complexes , CVC-Maisons ,
     * Détection, éclairage et installations électriques ». Des libellés
     * légitimes contenant « , » (virgule sans espace avant, ex. « Détection,
     * éclairage… ») ne doivent **pas** être cassés : le séparateur est donc
     * précisément la virgule précédée d'au moins un blanc (`/\s+,/`). Les
     * valeurs vides sont écartées ; la déduplication incombe à l'appelant.
     *
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    public static function splitCategories(iterable $values): array
    {
        $labels = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach (preg_split('/\s+,/', $value) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $labels[] = $part;
                }
            }
        }

        return $labels;
    }

    /**
     * Requête réelle derrière `distinctValues()` (à ne pas appeler seule) :
     * `categories` est un tableau JSON éclaté en libellés, les autres colonnes
     * sont des chaînes (`SELECT DISTINCT`). Dans les deux cas, valeurs vides
     * écartées, doublons supprimés même à la casse près, puis tri.
     */
    private static function queryDistinctValues(string $column, ?string $source = null): array
    {
        $values = static::query()
            ->when($source !== null && $source !== '', fn($q) => $q->where('source', $source))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column);

        return $values
            ->flatMap(function ($raw) use ($column) {
                // `categories` : le pluck Eloquent renvoie déjà des tableaux
                // décodés (cast `array`) ; on les éclate en libellés **et**
                // on sépare les éléments fusionnés « A , B , C » pour que le
                // sélecteur « Catégorie » n'affiche que des catégories
                // atomiques, sans doublons (déduplication juste après).
                if ($column === 'categories') {
                    $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

                    return self::splitCategories(is_array($decoded) ? $decoded : [$raw]);
                }

                return [$raw];
            })
            ->filter(fn($value) => is_string($value) && trim($value) !== '')
            // Déduplication insensible à la casse : « Montréal » et
            // « montréal » ne doivent pas revenir en double.
            ->unique(fn($value) => mb_strtolower(trim($value)))
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
    public static function getUniqueCategoriesAndMunicipalities(?string $source = null): array
    {
        $rawCategories = self::distinctValues('categories', $source);

        $parsedCategories = collect($rawCategories)
            ->flatMap(function ($item) {
                if (!is_string($item)) {
                    return is_array($item) ? $item : [];
                }

                $cleaned = trim($item);
                if (str_starts_with($cleaned, '[') && str_ends_with($cleaned, ']')) {
                    $decoded = json_decode($cleaned, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }

                return [$item];
            })
            ->flatMap(function ($item) {
                if (!is_string($item)) {
                    return [];
                }

                $item = trim($item, " \t\n\r\0\x0B\"'[]");

                $tokens = preg_split('/,\s*|\s*\|\s*|\s+and\s+|\s+et\s+|\s*&\s*/i', $item);

                return array_map(function ($token) {
                    return trim($token, " \t\n\r\0\x0B\"'[]");
                }, $tokens);
            })
            ->filter(function ($value) {
                if (empty($value)) {
                    return false;
                }

                // Optional: Remove obvious dangling truncations ending in prepositions/letters 
                // if they look incomplete (e.g. ends with trailing ' d', ' du', etc. without completing)
                $lower = mb_strtolower($value);
                if (preg_match('/\b(du|d\'|de|et|la|le)\s*$/ui', $lower) && mb_strlen($value) < 15) {
                    return false;
                }

                return true;
            })
            ->unique()
            ->values();

        // Remove shorter fragments that are exact prefixes of longer categories (e.g., "Structure du Bâtim" vs "Structure du Bâtiment")
        $sortedCategories = $parsedCategories->sort(function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a); // Sort longest first
        });

        $finalCategories = [];
        foreach ($sortedCategories as $category) {
            $isPrefixOfAnother = false;
            foreach ($finalCategories as $existing) {
                // If current category is a starting prefix of a longer already-accepted category, skip it
                if (str_starts_with(mb_strtolower($existing), mb_strtolower($category))) {
                    $isPrefixOfAnother = true;
                    break;
                }
            }
            if (!$isPrefixOfAnother) {
                $finalCategories[] = $category;
            }
        }

        sort($finalCategories);

        return [
            'categories' => array_values($finalCategories),
            'municipalities' => self::distinctValues('municipality', $source),
            'administrative_regions' => self::distinctValues('administrative_region', $source),
        ];
    }
}