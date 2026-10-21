<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use App\Services\Client\ClientSearchService;
use App\Support\PeriodFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private ClientSearchService $search,
    ) {}

    /**
     * GET /dashboard/stats?year=YYYY&month=M&week=W&day=D&source=…
     *
     * Tableau de bord role-aware (les gestionnaires voient les chiffres
     * globaux, les commerciaux/opérateurs les leurs) filtré par une période
     * **globale** — les mêmes sélecteurs Année / Mois / Semaine / Jour pilotent
     * les cartes, le graphique, les réservations et « Meilleurs employés » :
     *  - `year` seul (défaut : année courante) : année calendaire, le
     *    graphique rend **un point par mois** ;
     *  - `month` : mois calendaire de l'année choisie, un point par jour ;
     *  - `week` : semaine du mois choisi (1 = jours 1–7, 2 = jours 8–14…,
     *    la dernière est partielle), un point par jour de la semaine ;
     *    ignorée sans `month` (une semaine hors plage vaut « toutes ») ;
     *  - `day` : **jour seul** du mois choisi (`[00:00, minuit suivant)`),
     *    le plus fin des réglages — il rend `week` sans effet, et il est
     *    ignoré sans `month` ou hors plage. C'est ce que pose le raccourci
     *    « Ce jour ».
     * Côté commercial, les compteurs de liste (`prospects` / `libre` /
     * `double` / `info` / `bv` / `rappel`) sont **bornés par la période** :
     * ils comptent les clients « actifs » sur la période
     * (`Client::scopeActiveBetween()` — fiche créée, réservation posée ou
     * trace d'appel émise pendant la période), rappels BV / À rappeler compris
     * (réservation créée sur la période).
     * Exceptions : `stats.blacklisted_clients` reste un instantané des
     * clients **actuellement** blacklistés.
     *
     * `source` (origine du prospect, §13) : **filtre gestionnaire**, le même
     * que l’onglet « Source » de la Grande liste admin — il borne **tous** les
     * chiffres liés aux clients (prospects, blacklist, appels / OUI / échecs,
     * graphique, réservations, « Meilleurs employés »). Côté commercial /
     * opérateur, le périmètre est **décidé par le serveur** (la source de
     * l’entreprise, `applyCommercialSourceScope`) : le paramètre est ignoré.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $isManager = in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true);

        // Réglages normalisés (cascade serveur : `week`/`day` hors plage ou
        // sans `month` valent « toutes ») + bornes [début, fin) et granularité
        // du graphique — règles partagées avec `clients/overview`.
        $period = PeriodFilter::fromRequest($request);
        $year = $period['year'];
        $month = $period['month'];
        $week = $period['week'];
        $day = $period['day'];

        [$start, $end, $granularity] = PeriodFilter::resolve($year, $month, $week, $day);

        // Origine active (`''` / absent = « Toutes ») — gestionnaires seulement.
        $rawSource = $isManager ? trim((string) $request->query('source', '')) : '';
        $source = $rawSource !== '' ? $rawSource : null;

        if ($isManager) {
            return $this->adminStats($user, $start, $end, $granularity, $year, $month, $week, $day, $source);
        }

        // L'`OPERATOR` a un tableau de bord **simple**, indépendant de la
        // période : uniquement ses saisies de numéro de téléphone et ce qui
        // reste à faire dans sa liste.
        if ($user->role === 'OPERATOR') {
            return $this->operatorStats($user);
        }

        return $this->commercialStats($user, $start, $end, $granularity, $year, $month, $week, $day);
    }

    /**
     * Tableau de bord de l'`OPERATOR` — ses seuls chiffres utiles : les
     * numéros de téléphone qu'il a renseignés et la taille restante de sa file.
     *
     * Les saisies se lisent dans le journal des fiches : chaque édition humaine
     * produit une note `MISE_A_JOUR` dont la description égrène le diff champ
     * par champ (`ClientUpdateNote`), donc « une note qui parle de Téléphone »
     * = un numéro ajouté ou modifié par lui. Les autres champs édités (nom,
     * adresse…) n'y figurent pas et ne sont donc pas comptés.
     *
     * `pending_without_phone` reprend **exactement** le périmètre forcé de sa
     * Grande liste (`CommercialAdminController::applyOperatorScope`) : le
     * chiffre de la tuile égale le nombre de lignes rendues au clic (§9).
     *
     * Aucun graphique, aucune période : tous les compteurs sont instantanés.
     */
    private function operatorStats(User $user): JsonResponse
    {
        $phoneEdits = fn (): Builder => Note::query()
            ->where('sender_id', $user->id)
            ->where('type', Note::TYPE_MISE_A_JOUR)
            ->where('description', 'like', '%Téléphone : «%');

        $now = Carbon::now();
        $since = fn (int $days): string => $now->copy()
            ->subDays($days)
            ->startOfDay()
            ->toDateTimeString();

        $total = $phoneEdits()->count();
        $pending = Client::query()
            ->where('status', Client::STATUS_AVAILABLE)
            ->where('is_blacklisted', false)
            ->withoutPhone()
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'stats' => [
                    'phones_today' => $phoneEdits()->where('created_at', '>=', $since(0))->count(),
                    'phones_week' => $phoneEdits()->where('created_at', '>=', $since(6))->count(),
                    'phones_month' => $phoneEdits()->where('created_at', '>=', $since(29))->count(),
                    'phones_total' => $total,
                    'pending_without_phone' => $pending,
                ],
                'calls_by_day' => [],
                'top_commercials' => [],
            ],
        ]);
    }

    private function adminStats(User $user, Carbon $start, Carbon $end, string $granularity, int $year, ?int $month, ?int $week, ?int $day, ?string $source): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'source' => $source,
                'stats' => [
                    // Non bornés par l’origine : ce sont des compteurs
                    // structurels, pas de clients (« combien d’entreprises /
                    // de commerciaux dans l’application »).
                    'enterprises' => Enterprise::count(),
                    'commercials' => User::where('role', 'COMERCIAL')->count(),
                    // Tout le reste porte sur des **clients** : il suit
                    // l’origine sélectionnée, comme la Grande liste.
                    'prospects' => $this->clientsQuery($source)->count(),
                    'groups' => $this->countIn(
                        $this->scopeSourceByClient(ReservationGroup::query(), $source, 'reservations.client'),
                        $start,
                        $end
                    ),
                    'reservations' => $this->countIn(
                        $this->scopeSourceByClient(Reservation::query(), $source), $start, $end
                    ),
                    'period' => $this->periodCallStats($start, $end, null, $source),
                    'blacklisted_clients' => $this->blacklistedClientsCount($source),
                ],
                'calls_by_day' => $this->callsByDay($start, $end, $granularity, null, $source),
                'chart' => $this->chartMeta($year, $month, $week, $day),
                'top_commercials' => $this->topCommercials($start, $end, $source),
            ],
        ]);
    }

    private function commercialStats(User $user, Carbon $start, Carbon $end, string $granularity, int $year, ?int $month, ?int $week, ?int $day): JsonResponse
    {
        $id = $user->id;

        // « Grande liste » du commercial = **les lignes de `GET clients`**
        // actives sur la période : mêmes filtres partagés (`applyFilters`),
        // même périmètre « source » d'entreprise, mêmes exclusions de
        // privilège (§7.1) — plus la borne `activeBetween` (fiche créée,
        // réservation posée ou trace d'appel émise dans `[start, end)`), qui
        // fait réagir les tuiles au filtre Année / Mois / Semaine / Jour.
        // L'invariant « carte = lignes rendues » (§9) vaut donc **par
        // période** : la liste elle-même reste un instantané, la carte indique
        // ce que la période a touché.
        $perimeter = fn (): Builder => $this->excludeDoubleForUnprivileged(
            $this->search->applyCommercialSourceScope(
                $this->search->applyFilters(Client::query()->activeBetween($start, $end), [], $id),
                $user,
            ),
            $user,
        );

        // BV / À rappeler en attente = exactement les listes des pages
        // « BV » (`/auto-rappels`) et « Rappels » (`/reminders`) : rappels
        // `pending` dont la réservation porte le statut — bornés par la
        // période côté dashboard (réservation **créée** dans `[start, end)`),
        // alors que les badges du menu restent instantanés.
        $recallCount = fn (string $type): int => Rappel::where('comercial_id', $id)
            ->pending()
            ->whereHas('reservation', fn ($q) => $q
                ->where('status', $type)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end))
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'stats' => [
                    'groups' => $this->countIn(
                        ReservationGroup::where('comercial_id', $id), $start, $end
                    ),
                    'reservations' => $this->countIn(
                        Reservation::where('comercial_id', $id), $start, $end
                    ),
                    'period' => $this->periodCallStats($start, $end, $id),
                    'blacklisted_clients' => $this->blacklistedClientsCount(),
                    // Comptes de la liste, par statut affiché (invariants §9,
                    // bornés par la période) : `prospects` = « Tous », `libre`
                    // = les libres à réserver, `double` / `info` = les
                    // prospects encore **détenus**.
                    'prospects' => $perimeter()->count(),
                    'libre' => $this->search->filterByStatuses($perimeter(), Client::STATUS_AVAILABLE)->count(),
                    'double' => $this->search->filterByStatuses($perimeter(), Client::STATUS_DOUBLE)->count(),
                    'info' => $this->search->filterByStatuses($perimeter(), Client::STATUS_INFO)->count(),
                    'bv' => $recallCount(Reservation::STATUS_BV_VOICEMAIL),
                    'rappel' => $recallCount(Reservation::STATUS_CALL_BACK),
                ],
                'calls_by_day' => $this->callsByDay($start, $end, $granularity, $id),
                'chart' => $this->chartMeta($year, $month, $week, $day, $id),
                'top_commercials' => [],
            ],
        ]);
    }

    /**
     * §7.1 — un COMERCIAL **sans** privilège de libération ne voit plus les
     * « Double » qu'il détient : même exclusion que `GET clients`, pour que le
     * compteur de la carte égale les lignes rendues.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function excludeDoubleForUnprivileged(Builder $query, User $user): Builder
    {
        if ($user->role === 'COMERCIAL' && ! $user->has_permission) {
            return $query->where(fn (Builder $q) => $q
                ->whereNull('status')
                ->orWhere('status', '!=', Client::STATUS_DOUBLE));
        }

        return $query;
    }

    private function countIn(Builder $query, Carbon $start, Carbon $end): int
    {
        return $query->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
    }

    /**
     * Requête `clients` bornée par l’origine sélectionnée (§13) — `null` =
     * « Toutes », exactement comme l’onglet « Tous » de la Grande liste
     * (`ClientSearchService::filterBySource()`, comparaison exacte sur le
     * répertoire `sources`).
     *
     * @return Builder<Client>
     */
    private function clientsQuery(?string $source): Builder
    {
        return $this->search->filterBySource(Client::query(), $source);
    }

    /**
     * Même filtre appliqué à une requête qui **n’est pas** une requête
     * `Client` (notes, réservations, lots) : il passe par la relation
     * `client` de chaque ligne, donc par la même colonne `clients.source`.
     * `$relation` accepte un chemin Eloquent (`reservations.client` pour un
     * lot, dont la source se lit à travers les réservations qu’il contient).
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function scopeSourceByClient(Builder $query, ?string $source, string $relation = 'client'): Builder
    {
        if ($source === null) {
            return $query;
        }

        return $query->whereHas(
            $relation,
            fn (Builder $q) => $this->search->filterBySource($q, $source)
        );
    }

    /**
     * Cartes KPI de la période filtrée —
     *  - « Appels » : les **notes d'appel** créées par le bouton « Appel »
     *    (lançage RingCentral) : une note porteuse avec `call_log_id` ; un
     *    COMERCIAL ne voit que les siennes (via l'employé rattaché au
     *    journal d'appel — l'émetteur de la note est `SYSTEM`) ;
     *  - « OUI » : les notes d'issue `YES` de la période (un COMERCIAL ne
     *    voit que les siennes, `sender_id`) ;
     *  - « Échec » : appels − OUI (jamais négatif).
     *
     * @return array{calls: int, yes: int, failed: int}
     */
    private function periodCallStats(Carbon $start, Carbon $end, ?string $comercialId = null, ?string $source = null): array
    {
        $calls = $this->callNotesQuery($start, $end, $comercialId, $source)->count();

        $yes = $this->scopeSourceByClient(
            Note::query()
                ->where('type', Note::TYPE_YES)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->when($comercialId, fn ($q) => $q->where('sender_id', $comercialId)),
            $source
        )->count();

        return [
            'calls' => $calls,
            'yes' => $yes,
            'failed' => max($calls - $yes, 0),
        ];
    }

    /**
     * Requête des **notes d'appel** (créées par le bouton « Appel »
     * RingCentral : note porteuse d'un `call_log_id`) bornée par la période.
     * Le périmètre « mes appels » d'un commercial passe par l'employé
     * rattaché au journal d'appel, pas par `sender_id` (`SYSTEM`).
     */
    private function callNotesQuery(Carbon $start, Carbon $end, ?string $comercialId = null, ?string $source = null): Builder
    {
        return $this->scopeSourceByClient(
            Note::query()
                ->whereNotNull('call_log_id')
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->when(
                    $comercialId,
                    fn ($q) => $q->whereHas(
                        'callLog.employee',
                        fn ($e) => $e->where('user_id', $comercialId)
                    )
                ),
            $source
        );
    }

    /** Carte « Blacklist » : clients **actuellement** blacklistés (instantané, tous rôles). */
    private function blacklistedClientsCount(?string $source = null): int
    {
        return $this->clientsQuery($source)->where('is_blacklisted', true)->count();
    }

    /**
     * Série du graphique « Évolution des appels », alignée sur les cartes :
     * `total` = notes d'appel de la période (bouton « Appel » RingCentral),
     * `oui` = notes d'issue `YES`. Un point par jour (mois / semaine
     * filtrés) ou par mois (année seule), avec des zéros de remplissage.
     *
     * Le repli mensuel se fait en PHP (clé `Y-m` des jours) : `DATE_FORMAT`
     * n'est pas portable entre MySQL et SQLite.
     */
    private function callsByDay(Carbon $start, Carbon $end, string $granularity, ?string $comercialId = null, ?string $source = null): array
    {
        $totals = $this->countNotesByBucket(
            $this->callNotesQuery($start, $end, $comercialId, $source),
            $granularity
        );

        $ouis = $this->countNotesByBucket(
            $this->scopeSourceByClient(
                Note::query()
                    ->where('type', Note::TYPE_YES)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->when($comercialId, fn ($q) => $q->where('sender_id', $comercialId)),
                $source
            ),
            $granularity
        );

        return array_map(
            fn (array $point): array => [
                'date' => $point['key'],
                'label' => $point['label'],
                'total' => $totals[$point['key']] ?? 0,
                'oui' => $ouis[$point['key']] ?? 0,
            ],
            $this->buildPoints($start, $end, $granularity)
        );
    }

    /**
     * Compteurs regroupés par jour (`Y-m-d`), ou par mois (`Y-m`) quand la
     * granularité est mensuelle. `toBase()` remonte des lignes brutes plutôt
     * que des modèles `Note`.
     */
    private function countNotesByBucket(Builder $query, string $granularity): array
    {
        $rows = $query
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->toBase()
            ->get();

        $buckets = [];
        foreach ($rows as $raw) {
            $key = (string) $raw->day;
            if ($granularity === 'month') {
                $key = substr($key, 0, 7);
            }
            $buckets[$key] = ($buckets[$key] ?? 0) + (int) $raw->total;
        }

        return $buckets;
    }

    /** Points de la série : un par jour, ou un par mois calendaire. */
    private function buildPoints(Carbon $start, Carbon $end, string $granularity): array
    {
        $points = [];

        if ($granularity === 'month') {
            for ($d = $start->copy(); $d->lessThan($end); $d->addMonthNoOverflow()) {
                $points[] = ['key' => $d->format('Y-m'), 'label' => $d->format('n/Y')];
            }

            return $points;
        }

        for ($d = $start->copy(); $d->lessThan($end); $d->addDay()) {
            $points[] = ['key' => $d->toDateString(), 'label' => $d->format('d/m')];
        }

        return $points;
    }

    /**
     * Métadonnées du filtre pour l'UI : la sélection echoée et les années
     * réellement disponibles (étendue `MIN`/`MAX` des notes d'appel —
     * portable MySQL/SQLite — étendue à l'année courante et à l'année
     * choisie, pour que le sélecteur rende toujours sa valeur).
     *
     * @return array{year: int, month: ?int, week: ?int, day: ?int, years: list<int>}
     */
    private function chartMeta(int $year, ?int $month, ?int $week, ?int $day, ?string $comercialId = null): array
    {
        $query = Note::query()->whereNotNull('call_log_id');
        if ($comercialId) {
            $query->whereHas(
                'callLog.employee',
                fn ($e) => $e->where('user_id', $comercialId)
            );
        }

        $range = (array) $query->toBase()
            ->selectRaw('MIN(created_at) as mn, MAX(created_at) as mx')
            ->first();

        $current = (int) Carbon::today()->format('Y');
        $from = min(
            ! empty($range['mn']) ? (int) Carbon::parse($range['mn'])->format('Y') : $current,
            $current,
            $year
        );
        $to = max(
            ! empty($range['mx']) ? (int) Carbon::parse($range['mx'])->format('Y') : $current,
            $current,
            $year
        );

        return [
            'year' => $year,
            'month' => $month,
            'week' => $week,
            'day' => $day,
            'years' => range($to, $from),
        ];
    }

    /**
     * Tous les commerciaux pour la carte « Meilleurs employés » du tableau de
     * bord gestionnaire, **sur la période filtrée** et **sous l’origine
     * sélectionnée** (les notes sont rattachées à leur client via
     * `notes.client`) : nombre d'appels passés
     * (notes d'appel porteuses d'un `call_log_id` via l'employé, `calls`),
     * nombre d'OUI (`oui`, notes de type YES émises par l'utilisateur) et
     * clients distincts appelés / convertis. Tri par OUI décroissant, puis
     * par volume d'appels — plus de limite : la carte affiche **tous** les
     * employés (défilement côté client).
     */
    private function topCommercials(Carbon $start, Carbon $end, ?string $source = null): array
    {
        return User::where('role', 'COMERCIAL')->with('employee')->get()
            ->map(function (User $u) use ($start, $end, $source) {
                $calls = $this->callNotesQuery($start, $end, $u->id, $source);
                $oui = $this->scopeSourceByClient(
                    Note::where('sender_id', $u->id)
                        ->where('type', Note::TYPE_YES)
                        ->where('created_at', '>=', $start)
                        ->where('created_at', '<', $end),
                    $source
                );

                $clientsCalled = (clone $calls)->distinct()->count('client_id');
                $clientsOui = (clone $oui)->distinct()->count('client_id');

                return [
                    'id' => $u->id,
                    'name' => trim(($u->employee?->first_name ?? $u->first_name ?? '').' '.($u->employee?->last_name ?? $u->last_name ?? '')) ?: $u->email,
                    'email' => $u->email,
                    'calls' => $calls->count(),
                    'oui' => $oui->count(),
                    'reservations' => $this->countIn(
                        $this->scopeSourceByClient(Reservation::where('comercial_id', $u->id), $source),
                        $start,
                        $end
                    ),
                    'clients_called' => $clientsCalled,
                    'clients_oui' => $clientsOui,
                ];
            })
            ->sortByDesc('calls')
            ->sortByDesc('oui')
            ->values()
            ->all();
    }
}
