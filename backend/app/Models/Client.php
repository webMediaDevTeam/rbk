<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class Client extends Model
{
    use HasFactory, HasUuids;

    // `created_at` + `updated_at` : Eloquent maintient `updated_at` à chaque
    // modification (colonnes `timestamp` gérées par le modèle, pas par la DB).

    protected $table = 'clients';

    protected $fillable = [
        'name',
        'enterprise_name',
        'categories',
        'status',
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
        } catch (\Throwable) {
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
            ->where('status', 'AVAILABLE')
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

    // ------------------------------------------------------------------
    // Statut AFFICHÉ (jamais stocké, jamais utilisé pour filtrer).
    // ------------------------------------------------------------------

    /** Réservation BV encore en cours de traitement (couleur warning). */
    public const DISPLAY_IN_PROGRESS = 'IN_PROGRESS';

    /** Réservation INJOINABLE encore en cours de traitement (couleur info). */
    public const DISPLAY_IN_PROGRESS_RECALL = 'IN_PROGRESS_RECALL';

    /**
     * Statut « reformulé » d'un client, pour l'affichage dans les listes.
     *
     * Règle métier (docs/RULES.md §2) — un client encore **RESERVED** est
     * qualifié par l'état de sa **dernière réservation** :
     *
     *  - OUI         -> SUCCESS            « Succès »
     *  - NON         -> UNAVAILABLE_TEMP   « Non disponible pour le moment »
     *  - BV          -> IN_PROGRESS        « En cours de traitement »
     *  - INJOINABLE  -> IN_PROGRESS_RECALL « En cours de traitement »
     *  - sinon       -> son statut courant (« Réservé »)
     *
     * Tous les autres statuts (AVAILABLE, SUCCESS, UNAVAILABLE_TEMP,
     * BLACKLISTED…) sont retournés tels quels. `clients.status` brut n'est
     * **jamais modifié** : les filtres, les KPI et le workflow continuent de
     * le lire directement.
     *
     * 1 seule requête au maximum (si aucune réservation n'est préchargée) ;
     * passez par `loadLatestReservations()` pour une liste.
     */
    public function displayStatus(): string
    {
        if ($this->status !== 'RESERVED') {
            return $this->status;
        }

        return match ($this->latestReservationStatus()) {
            'OUI' => 'SUCCESS',
            'NON' => 'UNAVAILABLE_TEMP',
            'BV' => self::DISPLAY_IN_PROGRESS,
            'INJOINABLE' => self::DISPLAY_IN_PROGRESS_RECALL,
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
     * Précharge la dernière réservation de tous les clients « RESERVED »
     * d'un lot en **une seule requête** (sinon `displayStatus()` ferait une
     * requête par ligne à formatter).
     *
     * @param  iterable<int|string, mixed>  $clients
     */
    public static function loadLatestReservations(iterable $clients): void
    {
        $pending = collect($clients)
            ->filter(fn ($client) => $client instanceof self
                && $client->status === 'RESERVED'
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
            ->get(['id', 'client_id', 'status', 'created_at']);

        foreach ($rows as $row) {
            // Déjà trié du plus récent au plus ancien : on garde le 1er vu.
            $latest[$row->client_id] ??= $row;
        }

        foreach ($pending as $client) {
            $client->setRelation('latestReservation', $latest[$client->id] ?? null);
        }
    }

    public function callOutcomes(): HasMany
    {
        return $this->hasMany(CallOutcome::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
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
        return 'clients:distinct:' . $column;
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
            'categories'             => self::distinctValues('categories'),
            'municipalities'         => self::distinctValues('municipality'),
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

        return $query->orWhereRaw('CAST(' . $wrapped . ' AS CHAR) LIKE ?', [$like]);
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
