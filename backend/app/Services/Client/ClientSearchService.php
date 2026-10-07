<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtres et recherche de la page « Prospects » / « Grande liste »
 * (docs/RULES.md §7-§9) — extraits des scopes de `App\Models\Client`.
 *
 * Méthodes d'instance : à injecter dans les contrôleurs
 * (`__construct(private ClientSearchService $search)`), ex-`scopeProspectList()`
 * devient `applyFilters()`, ex-`scopeSearchAll()` devient `search()`.
 */
class ClientSearchService
{
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

    // ------------------------------------------------------------------
    // Filtres « facettes » (municipalité / région / catégorie / statuts)
    // ------------------------------------------------------------------

    /**
     * Filtre par municipalité (libellé exact) :
     *   $search->filterByMunicipalities(Client::query(), ['Québec', 'Lévis'])
     *
     * Le filtre s'applique à la requête du modèle (casts, scopes globaux et
     * événements préservés) — plus de DB::table() ni de sous-requête
     * whereIn('id', …) qui doublonnait les requêtes.
     */
    public function filterByMunicipalities(Builder $query, string|array $municipalities): Builder
    {
        $values = self::nonEmptyValues($municipalities);

        return $values === [] ? $query : $query->whereIn('municipality', $values);
    }

    /**
     * Filtre par région administrative (libellé exact), même mécanique que
     * `filterByMunicipalities()` :
     *   $search->filterByAdministrativeRegions(Client::query(), 'Lanaudière')
     */
    public function filterByAdministrativeRegions(Builder $query, string|array $regions): Builder
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
    public function filterByCategories(Builder $query, string|array $categories): Builder
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
     *   $search->filterByStatuses(Client::query(), 'AVAILABLE,RESERVED')  // chaîne
     *   $search->filterByStatuses(Client::query(), ['AVAILABLE'])         // tableau
     *
     * Valeurs validées contre `Client::STATUSES` + `SANS_TELEPHONE` (une
     * valeur inconnue est ignorée), sans doublon ; vide / null = aucun
     * filtre.
     *
     * Une ligne **blacklistée** appartient au seau `BLACKLISTED`, quel que
     * soit son `status` (une donnée réelle a encore `status = AVAILABLE`) :
     * elle n'entre donc que si `BLACKLISTED` est demandé. C'est la définition
     * **exacte** de `by_status` (`GET clients/overview`) — le chiffre affiché
     * sur un badge vaut alors le nombre de lignes renvoyées après clic.
     *
     * `SANS_TELEPHONE` est un seau **dérivé** (colonne `phone` vide, cf.
     * `scopeWithoutPhone()`) : il recoupe les autres seaux client (un
     * prospect sans numéro est aussi `AVAILABLE` ou blacklisté). La
     * sélection reste unique dans l'UI, donc le compteur du badge = lignes
     * rendues tient ; en revanche les compteurs ne sont **pas** additionnables
     * avec ceux des autres seaux client.
     */
    public function filterByStatuses(Builder $query, string|array|null $statuses): Builder
    {
        $values = $statuses === null
            ? []
            : (is_array($statuses) ? $statuses : explode(',', (string) $statuses));

        $statuses = array_values(array_unique(array_intersect(
            array_map(fn ($value) => trim((string) $value), $values),
            [...Client::STATUSES, Client::STATUS_SANS_TELEPHONE],
        )));

        if ($statuses === []) {
            return $query;
        }

        $others = array_values(array_diff(
            $statuses,
            [Client::STATUS_BLACKLISTED, Client::STATUS_SANS_TELEPHONE],
        ));
        $hasBlacklist = in_array(Client::STATUS_BLACKLISTED, $statuses, true);
        $hasWithoutPhone = in_array(Client::STATUS_SANS_TELEPHONE, $statuses, true);

        return $query->where(function (Builder $nested) use ($others, $hasBlacklist, $hasWithoutPhone) {
            $branches = [];

            if ($others !== []) {
                $branches[] = fn (Builder $q) => $q
                    ->whereIn('status', $others)
                    ->where('is_blacklisted', false);
            }

            if ($hasBlacklist) {
                $branches[] = fn (Builder $q) => $q->where('is_blacklisted', true);
            }

            if ($hasWithoutPhone) {
                $branches[] = fn (Builder $q) => $q->withoutPhone();
            }

            // Union `OR` des seaux demandés (un seul dans l'UI).
            foreach ($branches as $index => $branch) {
                $index === 0 ? $nested->where($branch) : $nested->orWhere($branch);
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
     * réservation, et ce filtre l'exclut donc toujours. Combiner
     * `filterByStatuses()` et `filterByReservationStatuses()` en `OR`
     * donne alors une union exacte.
     */
    public function filterByReservationStatuses(Builder $query, string|array|null $statuses): Builder
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
            ->where('status', '!=', Client::STATUS_AVAILABLE)
            ->whereIn(
                'current_reservation_id',
                Reservation::query()->whereIn('status', $statuses)->select('id')
            );
    }

    /**
     * Application des **deux paramètres de badges** de la colonne « Statut »
     * (docs/RULES.md §9) à une requête — partagée par la Grande liste
     * admin, l'historique d'un employé et l'historique d'une entreprise :
     *
     *   - `$status`             → statut **client** (Disponible / Blacklist /
     *     Sans téléphone), `filterByStatuses()` ;
     *   - `$reservationStatus`  → statut de la **réservation courante**
     *     (Oui / Non / BV / À rappeler / « - »),
     *     `filterByReservationStatuses()`.
     *
     * Les deux jeux sont disjoints (sauf `SANS_TELEPHONE`, seau dérivé qui
     * recoupe les autres) et combinés en **union `OR`** quand les deux sont
     * fournis.
     */
    public function applyDisplayStatusFilters(
        Builder $query,
        string|array|null $status,
        string|array|null $reservationStatus,
    ): Builder {
        $hasStatus = is_array($status) ? $status !== [] : filled($status);
        $hasReservation = is_array($reservationStatus) ? $reservationStatus !== [] : filled($reservationStatus);

        if ($hasStatus && $hasReservation) {
            return $query->where(function (Builder $q) use ($status, $reservationStatus) {
                $q->where(fn (Builder $inner) => $this->filterByStatuses($inner, $status))
                    ->orWhere(fn (Builder $inner) => $this->filterByReservationStatuses($inner, $reservationStatus));
            });
        }

        if ($hasStatus) {
            return $this->filterByStatuses($query, $status);
        }

        if ($hasReservation) {
            return $this->filterByReservationStatuses($query, $reservationStatus);
        }

        return $query;
    }

    /**
     * Compteurs des **8 badges** de la colonne « Statut » pour un périmètre
     * donné : `$base` reconstruit une requête **fraîche** (même base, sans
     * filtre de statut) et chaque compteur est produit **par le filtre qui
     * pilote le badge** — le chiffre affiché vaut donc le nombre de lignes
     * rendues après clic sur ce badge.
     *
     * @param  callable(): Builder  $base
     * @return array<string, int>
     */
    public function displayStatusCounts(callable $base): array
    {
        $counts = [];

        foreach ([Client::STATUS_AVAILABLE, Client::STATUS_BLACKLISTED, Client::STATUS_SANS_TELEPHONE] as $bucket) {
            $counts[$bucket] = $this->filterByStatuses($base(), $bucket)->count();
        }

        foreach ([
            Reservation::STATUS_YES,
            Reservation::STATUS_NO,
            Reservation::STATUS_BV_VOICEMAIL,
            Reservation::STATUS_CALL_BACK,
            Reservation::STATUS_PENDING,
        ] as $bucket) {
            $counts[$bucket] = $this->filterByReservationStatuses($base(), $bucket)->count();
        }

        return $counts;
    }

    // ------------------------------------------------------------------
    // Recherche plein texte (toutes colonnes, §8)
    // ------------------------------------------------------------------

    /**
     * Recherche « **toutes colonnes** » (docs/RULES.md §8) — commune aux
     * listes de prospects : `GET /clients` (page Grande liste commerciale +
     * réservation d'un lot), `GET /commercials/clients` (Grande liste admin)
     * et l'historique d'un employé.
     *
     * Les branches sont regroupées dans **un seul** groupe `OR` : la
     * recherche est une union de `searchByName()`, `searchByContact()`,
     * `searchByAddress()`, `searchByReference()`, `searchByJson()` et
     * `searchByPhone()`.
     *
     * **Téléphone indifféremment formaté** : la saisie est ramenée aux
     * chiffres seuls puis comparée à la colonne `phone` également privée de
     * sa ponctuation — `9500301807`, `(9500) 301-807`, `9900-0895-49` et
     * `+1 (9900) 0895-49` se retrouvent donc mutuellement, quel que soit le
     * côté qui est formaté (et même si les deux le sont, différemment).
     *
     * @param  string  $search  Terme saisi par l'utilisateur (tel quel).
     */
    public function search(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(function (Builder $q) use ($like, $search) {
            $this->searchByName($q, $like);
            $this->searchByContact($q, $like);
            $this->searchByAddress($q, $like);
            $this->searchByReference($q, $like);
            $this->searchByJson($q, $like);
            $this->searchByPhone($q, $search);
        });
    }

    /**
     * Recherche par **nom** : raison sociale (`name`) et nom d'entreprise
     * (`enterprise_name`).
     *
     * @param  string  $like  Motif déjà entouré de `%` (ex. `%Québec%`).
     */
    public function searchByName(Builder $query, string $like): Builder
    {
        return $query
            ->where('name', 'LIKE', $like)
            ->orWhere('enterprise_name', 'LIKE', $like);
    }

    /**
     * Recherche par **téléphone**, saisie indifféremment formatée : le
     * motif brut est comparé tel quel, puis la saisie ramenée aux chiffres
     * seuls est comparée à la colonne `phone` également privée de sa
     * ponctuation.
     *
     * `phone` re-mis en forme par `Client::digitsOnlySql()` : REPLACE()
     * imbriqué et non REGEXP, seule écriture portable MySQL / SQLite
     * (les tests tournent sur SQLite in-memory — AGENTS.md §2).
     */
    public function searchByPhone(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';
        $digits = preg_replace('/\D+/', '', $search);
        $phone = $query->getQuery()->getGrammar()->wrap('phone');

        $query->orWhere('phone', 'LIKE', $like);

        if ($digits !== '') {
            $query->orWhereRaw(Client::digitsOnlySql($phone).' LIKE ?', ['%'.$digits.'%']);
        }

        return $query;
    }

    /**
     * Recherche par **adresse** : municipalité du client.
     *
     * @param  string  $like  Motif déjà entouré de `%`.
     */
    public function searchByAddress(Builder $query, string $like): Builder
    {
        return $query->orWhere('municipality', 'LIKE', $like);
    }

    /**
     * Recherche par **contact** : courriel et NEQ.
     *
     * @param  string  $like  Motif déjà entouré de `%`.
     */
    public function searchByContact(Builder $query, string $like): Builder
    {
        return $query
            ->orWhere('email', 'LIKE', $like)
            ->orWhere('neq', 'LIKE', $like);
    }

    /**
     * Recherche par **référence de licence** (colonne `licence_number` et
     * `licence_propre_numero`, clé métier de l'import §12).
     *
     * @param  string  $like  Motif déjà entouré de `%`.
     */
    public function searchByReference(Builder $query, string $like): Builder
    {
        return $query
            ->orWhere('licence_number', 'LIKE', $like)
            ->orWhere('licence_propre_numero', 'LIKE', $like);
    }

    /**
     * Recherche dans les colonnes JSON (`respondents`, `categories`,
     * `authorized_categories`) — sous-chaîne via `orWhereJsonTextLike()`.
     *
     * @param  string  $like  Motif déjà entouré de `%`.
     */
    public function searchByJson(Builder $query, string $like): Builder
    {
        foreach (['respondents', 'categories', 'authorized_categories'] as $column) {
            $this->orWhereJsonTextLike($query, $column, $like);
        }

        return $query;
    }

    /**
     * Recherche par sous-chaîne dans une colonne JSON (`respondents`,
     * `categories`, `authorized_categories`).
     *
     * `whereJsonContains()` exige une valeur exacte et `JSON_SEARCH()` est
     * exclusif à MySQL : LIKE sur la représentation textuelle reste la seule
     * option portable pour une recherche libre. C'est la seule expression SQL
     * brute du service.
     */
    private function orWhereJsonTextLike(Builder $query, string $column, string $like): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return $query->orWhereRaw('CAST('.$wrapped.' AS CHAR) LIKE ?', [$like]);
    }

    // ------------------------------------------------------------------
    // Liste partagée Prospects / réservation d'un lot
    // ------------------------------------------------------------------

    /**
     * Requete **partagee** de la page « Prospects » : filtres, exclusions
     * d'affichage et tri (ex-`Client::scopeProspectList()`).
     *
     * `GET /clients` **et** `POST clients/reserver` passent par cette
     * méthode : le lot réservé est exactement celui que l'employé voit à
     * l'écran, dans le même ordre (docs/RULES.md §7 — « même filtre et
     * même tri que la page »). `page` / `per_page` sont ignorés : la
     * réservation prend le premier lot de candidats de cette liste.
     *
     * @param  array<string, mixed>  $input  Paramètres de requête de la page.
     */
    public function applyFilters(Builder $query, array $input = []): Builder
    {
        if ($municipality = $input['municipality'] ?? null) {
            $this->filterByMunicipalities($query, $municipality);
        }

        if ($category = $input['category'] ?? null) {
            $this->filterByCategories($query, $category);
        }

        if ($region = $input['administrative_region'] ?? null) {
            $this->filterByAdministrativeRegions($query, $region);
        }

        if ($search = $input['search'] ?? null) {
            // Recherche « toutes colonnes » + téléphone indifféremment
            // formaté — même filtre que la Grande liste admin (§8).
            $this->search($query, $search);
        }

        // Exclusions de la page : hors liste noire, réellement disponible,
        // au moins un numéro (un prospect sans téléphone ne peut pas être
        // appelé — statut dérivé `SANS_TELEPHONE`, invisible côté commercial),
        // aucune réservation active en cours (ni la sienne ni celle d'un
        // autre employé).
        $query->where('is_blacklisted', false)
            ->available()
            ->withPhone()
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
