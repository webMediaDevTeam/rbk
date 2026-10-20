<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /dashboard/stats?year=YYYY&month=M&week=W
     *
     * Tableau de bord role-aware (les gestionnaires voient les chiffres
     * globaux, les commerciaux/opérateurs les leurs) filtré par une période
     * **globale** — les mêmes sélecteurs Année / Mois / Semaine pilotent les
     * cartes, le graphique, les réservations et « Meilleurs employés » :
     *  - `year` seul (défaut : année courante) : année calendaire, le
     *    graphique rend **un point par mois** ;
     *  - `month` : mois calendaire de l'année choisie, un point par jour ;
     *  - `week` : semaine du mois choisi (1 = jours 1–7, 2 = jours 8–14…,
     *    la dernière est partielle), un point par jour de la semaine ;
     *    ignorée sans `month` (une semaine hors plage vaut « toutes »).
     * Exception : `stats.blacklisted_clients` reste un instantané des
     * clients **actuellement** blacklistés.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $year = (int) $request->query('year', 0);
        $year = $year > 0 ? $year : (int) Carbon::today()->format('Y');

        $month = (int) $request->query('month', 0);
        $month = ($month >= 1 && $month <= 12) ? $month : null;

        $week = (int) $request->query('week', 0);
        $week = $week > 0 ? $week : null;
        if ($month !== null && $week !== null) {
            $daysInMonth = (int) Carbon::createFromDate($year, $month, 1)->endOfMonth()->format('j');
            if ($week > (int) ceil($daysInMonth / 7)) {
                $week = null;
            }
        }

        [$start, $end, $granularity] = $this->resolvePeriod($year, $month, $week);

        if (in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $this->adminStats($user, $start, $end, $granularity, $year, $month, $week);
        }

        return $this->commercialStats($user, $start, $end, $granularity, $year, $month, $week);
    }

    private function adminStats(User $user, Carbon $start, Carbon $end, string $granularity, int $year, ?int $month, ?int $week): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'stats' => [
                    'enterprises' => Enterprise::count(),
                    'commercials' => User::where('role', 'COMERCIAL')->count(),
                    'prospects' => Client::count(),
                    'groups' => $this->countIn(ReservationGroup::query(), $start, $end),
                    'reservations' => $this->countIn(Reservation::query(), $start, $end),
                    'period' => $this->periodCallStats($start, $end),
                    'blacklisted_clients' => $this->blacklistedClientsCount(),
                ],
                'calls_by_day' => $this->callsByDay($start, $end, $granularity),
                'chart' => $this->chartMeta($year, $month, $week),
                'top_commercials' => $this->topCommercials($start, $end),
            ],
        ]);
    }

    private function commercialStats(User $user, Carbon $start, Carbon $end, string $granularity, int $year, ?int $month, ?int $week): JsonResponse
    {
        $id = $user->id;

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
                ],
                'calls_by_day' => $this->callsByDay($start, $end, $granularity, $id),
                'chart' => $this->chartMeta($year, $month, $week, $id),
                'top_commercials' => [],
            ],
        ]);
    }

    /**
     * Bornes de la période filtrante + granularité du graphique :
     * année seule = `[1er janvier, 1er janvier N+1)` mensuel ; mois =
     * `[1er, 1er du mois suivant)` journalier ; semaine N du mois =
     * `[jour (N−1)×7+1, min(N×7, dernier jour)]` journalier.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string} `[début, fin exclusive, granularité]`
     */
    private function resolvePeriod(int $year, ?int $month, ?int $week): array
    {
        if ($month === null) {
            $start = Carbon::createFromDate($year, 1, 1)->startOfDay();

            return [$start, $start->copy()->addYear(), 'month'];
        }

        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfDay();

        if ($week === null) {
            return [$monthStart, $monthStart->copy()->addMonth(), 'day'];
        }

        $daysInMonth = (int) $monthStart->copy()->endOfMonth()->format('j');
        $from = min(($week - 1) * 7 + 1, $daysInMonth);
        $to = min($week * 7, $daysInMonth);

        return [
            $monthStart->copy()->addDays($from - 1),
            $monthStart->copy()->addDays($to),
            'day',
        ];
    }

    private function countIn(Builder $query, Carbon $start, Carbon $end): int
    {
        return $query->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
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
    private function periodCallStats(Carbon $start, Carbon $end, ?string $comercialId = null): array
    {
        $calls = $this->callNotesQuery($start, $end, $comercialId)->count();

        $yes = Note::query()
            ->where('type', Note::TYPE_YES)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->when($comercialId, fn ($q) => $q->where('sender_id', $comercialId))
            ->count();

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
    private function callNotesQuery(Carbon $start, Carbon $end, ?string $comercialId = null): Builder
    {
        return Note::query()
            ->whereNotNull('call_log_id')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->when(
                $comercialId,
                fn ($q) => $q->whereHas(
                    'callLog.employee',
                    fn ($e) => $e->where('user_id', $comercialId)
                )
            );
    }

    /** Carte « Blacklist » : clients **actuellement** blacklistés (instantané, tous rôles). */
    private function blacklistedClientsCount(): int
    {
        return Client::where('is_blacklisted', true)->count();
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
    private function callsByDay(Carbon $start, Carbon $end, string $granularity, ?string $comercialId = null): array
    {
        $totals = $this->countNotesByBucket(
            $this->callNotesQuery($start, $end, $comercialId),
            $granularity
        );

        $ouis = $this->countNotesByBucket(
            Note::query()
                ->where('type', Note::TYPE_YES)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->when($comercialId, fn ($q) => $q->where('sender_id', $comercialId)),
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
     * @return array{year: int, month: ?int, week: ?int, years: list<int>}
     */
    private function chartMeta(int $year, ?int $month, ?int $week, ?string $comercialId = null): array
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
            'years' => range($to, $from),
        ];
    }

    /**
     * Tous les commerciaux pour la carte « Meilleurs employés » du tableau de
     * bord gestionnaire, **sur la période filtrée** : nombre d'appels passés
     * (notes d'appel porteuses d'un `call_log_id` via l'employé, `calls`),
     * nombre d'OUI (`oui`, notes de type YES émises par l'utilisateur) et
     * clients distincts appelés / convertis. Tri par OUI décroissant, puis
     * par volume d'appels — plus de limite : la carte affiche **tous** les
     * employés (défilement côté client).
     */
    private function topCommercials(Carbon $start, Carbon $end): array
    {
        return User::where('role', 'COMERCIAL')->with('employee')->get()
            ->map(function (User $u) use ($start, $end) {
                $calls = $this->callNotesQuery($start, $end, $u->id);
                $oui = Note::where('sender_id', $u->id)
                    ->where('type', Note::TYPE_YES)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end);

                $clientsCalled = (clone $calls)->distinct()->count('client_id');
                $clientsOui = (clone $oui)->distinct()->count('client_id');

                return [
                    'id' => $u->id,
                    'name' => trim(($u->employee?->first_name ?? $u->first_name ?? '').' '.($u->employee?->last_name ?? $u->last_name ?? '')) ?: $u->email,
                    'email' => $u->email,
                    'calls' => $calls->count(),
                    'oui' => $oui->count(),
                    'reservations' => $this->countIn(Reservation::where('comercial_id', $u->id), $start, $end),
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
