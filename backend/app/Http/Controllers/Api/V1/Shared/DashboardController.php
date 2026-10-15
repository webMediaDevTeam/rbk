<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /** Périodes du graphique « Évolution journalière des appels » (`?period=`). */
    private const PERIODS = ['week', 'month', 'year'];

    /**
     * Issues d'appel comptées au tableau de bord : les `CALL_TYPES` du modèle
     * **plus** `BLACKLISTED` — un appel terminé par une mise en liste noire
     * reste un appel effectué : le total du jour inclut donc appels validés,
     * refusés et black-listés.
     */
    private const DASHBOARD_CALL_TYPES = [...Note::CALL_TYPES, Note::TYPE_BLACKLISTED];

    /**
     * GET /dashboard/stats?period=week|month|year&year=YYYY&month=M
     *
     * Tableau de bord role-aware : les gestionnaires voient les chiffres
     * globaux, les commerciaux les leurs. `period` pilote la série
     * `calls_by_day` du graphique :
     *  - `week`  : 7 derniers jours (un point par jour) ;
     *  - `month` : 30 derniers jours, ou — si `year`+`month` fournis — **un
     *    point par jour du mois calendaire choisi** ;
     *  - `year`  : 12 derniers mois, ou — si `year` fourni — **un point par
     *    mois de l'année calendaire choisie**.
     * Les cartes d'appels (`stats.today.*`) comptent toujours la journée en
     * cours.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $period = (string) $request->query('period', 'week');
        $period = in_array($period, self::PERIODS, true) ? $period : 'week';

        $year = (int) $request->query('year', 0);
        $year = $year > 0 ? $year : null;

        $month = (int) $request->query('month', 0);
        $month = ($month >= 1 && $month <= 12) ? $month : null;

        if (in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $this->adminStats($user, $period, $year, $month);
        }

        return $this->commercialStats($user, $period, $year, $month);
    }

    private function adminStats(User $user, string $period, ?int $year, ?int $month): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'stats' => [
                    'enterprises' => Enterprise::count(),
                    'commercials' => User::where('role', 'COMERCIAL')->count(),
                    'prospects' => Client::count(),
                    'groups' => ReservationGroup::count(),
                    'reservations' => Reservation::count(),
                    'today' => $this->todayCallStats(),
                ],
                'calls_by_day' => $this->callsByDay($period, null, $year, $month),
                'chart' => $this->chartMeta($period, $year, $month),
                'top_commercials' => $this->topCommercials(),
            ],
        ]);
    }

    private function commercialStats(User $user, string $period, ?int $year, ?int $month): JsonResponse
    {
        $id = $user->id;

        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'stats' => [
                    'groups' => ReservationGroup::where('comercial_id', $id)->count(),
                    'reservations' => Reservation::where('comercial_id', $id)->count(),
                    'today' => $this->todayCallStats($id),
                ],
                'calls_by_day' => $this->callsByDay($period, $id, $year, $month),
                'chart' => $this->chartMeta($period, $year, $month, $id),
                'top_commercials' => [],
            ],
        ]);
    }

    /**
     * Appels de la journée en cours, ventilés par issue — cartes KPI
     * « Appels aujourd'hui / validés (OUI) / refusés / black-listés ». Une
     * seule requête groupée par `type` ; un COMERCIAL ne voit que ses
     * propres appels (`sender_id`), un gestionnaire ceux de toute l'équipe.
     *
     * @return array{calls: int, yes: int, no: int, blacklisted: int}
     */
    private function todayCallStats(?string $comercialId = null): array
    {
        $query = Note::query()
            ->where('created_at', '>=', Carbon::today()->startOfDay())
            ->whereIn('type', self::DASHBOARD_CALL_TYPES)
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type');

        if ($comercialId) {
            $query->where('sender_id', $comercialId);
        }

        $rows = $query->pluck('total', 'type');

        return [
            'calls' => (int) $rows->sum(),
            'yes' => (int) ($rows->get(Note::TYPE_YES) ?? 0),
            'no' => (int) ($rows->get(Note::TYPE_NO) ?? 0),
            'blacklisted' => (int) ($rows->get(Note::TYPE_BLACKLISTED) ?? 0),
        ];
    }

    /**
     * Série du graphique « Évolution journalière des appels ». Les bornes, la
     * granularité et les points viennent de `resolveSeries()` : semaine / mois
     * glissant / mois calendaire choisi = un point par jour ; année = un point
     * par mois.
     *
     * Chaque point : `{date, label, total, oui}` — le taux (`oui / total`,
     * axe gauche en %) est calculé côté client. Le repli mensuel se fait en
     * PHP (clé `Y-m` des jours) : `DATE_FORMAT` n'est pas portable entre MySQL
     * et SQLite.
     */
    private function callsByDay(string $period = 'week', ?string $comercialId = null, ?int $year = null, ?int $month = null): array
    {
        [$start, $end, $series, $granularity] = $this->resolveSeries($period, $year, $month);

        $query = Note::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->whereIn('type', self::DASHBOARD_CALL_TYPES)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN type = '".Note::TYPE_YES."' THEN 1 ELSE 0 END) as oui")
            ->groupBy('day')
            ->orderBy('day');

        if ($comercialId) {
            $query->where('sender_id', $comercialId);
        }

        // `toBase()` remonte des lignes brutes (`stdClass`) plutôt que des
        // modèles `Note` : les colonnes agrégées sont ainsi accessibles en
        // tant que vraies clés, aplatis en buckets (`day` → compteurs) pour la
        // boucle des points.
        $buckets = [];
        foreach ($query->toBase()->get() as $raw) {
            $row = (array) $raw;
            $day = (string) $row['day'];
            $buckets[$day]['total'] = ($buckets[$day]['total'] ?? 0) + (int) $row['total'];
            $buckets[$day]['oui'] = ($buckets[$day]['oui'] ?? 0) + (int) $row['oui'];
        }

        // Granularité mensuelle : repli des jours par mois calendaire (`Y-m`).
        if ($granularity === 'month') {
            $byMonth = [];
            foreach ($buckets as $day => $counts) {
                $monthKey = substr($day, 0, 7);
                $byMonth[$monthKey]['total'] = ($byMonth[$monthKey]['total'] ?? 0) + $counts['total'];
                $byMonth[$monthKey]['oui'] = ($byMonth[$monthKey]['oui'] ?? 0) + $counts['oui'];
            }
            $buckets = $byMonth;
        }

        // Points rendus dans l'ordre, avec des zéros de remplissage pour les
        // jours / mois sans appel.
        return array_map(
            fn (array $point): array => [
                'date' => $point['key'],
                'label' => $point['label'],
                'total' => $buckets[$point['key']]['total'] ?? 0,
                'oui' => $buckets[$point['key']]['oui'] ?? 0,
            ],
            $series
        );
    }

    /**
     * Détermine la série de points du graphique pour la période demandée.
     *
     * @return array{0: Carbon, 1: Carbon, 2: list<array{key: string, label: string}>, 3: string}
     *                                                                                            `[début, fin exclusive, points, granularité]`
     */
    private function resolveSeries(string $period, ?int $year, ?int $month): array
    {
        // Année : un point par mois. Année calendaire choisie, sinon 12
        // derniers mois calendaires.
        if ($period === 'year') {
            $start = $year
                ? Carbon::createFromDate($year, 1, 1)->startOfDay()
                : Carbon::today()->subMonthsNoOverflow(11)->startOfMonth();
            $end = $year
                ? $start->copy()->addYear()
                : Carbon::today()->addDay()->startOfDay();

            $series = [];
            foreach (range(11, 0) as $i) {
                $d = $year
                    ? $start->copy()->addMonthsNoOverflow(11 - $i)
                    : Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
                $series[] = ['key' => $d->format('Y-m'), 'label' => $d->format('n/Y')];
            }

            return [$start, $end, $series, 'month'];
        }

        // Mois calendaire choisi : un point par jour (1 → dernier jour).
        if ($period === 'month' && $year && $month) {
            $start = Carbon::createFromDate($year, $month, 1)->startOfDay();
            $end = $start->copy()->addMonth();
            $days = (int) $start->copy()->endOfMonth()->format('j');

            $series = [];
            foreach (range(0, $days - 1) as $i) {
                $d = $start->copy()->addDays($i);
                $series[] = ['key' => $d->toDateString(), 'label' => $d->format('d/m')];
            }

            return [$start, $end, $series, 'day'];
        }

        // Semaine (7 j) ou mois glissant (30 j) : un point par jour.
        $points = $period === 'month' ? 30 : 7;
        $start = Carbon::today()->subDays($points - 1)->startOfDay();
        $end = Carbon::today()->addDay()->startOfDay();

        $series = [];
        foreach (range($points - 1, 0) as $i) {
            $d = Carbon::today()->subDays($i);
            $series[] = ['key' => $d->toDateString(), 'label' => $d->format('d/m')];
        }

        return [$start, $end, $series, 'day'];
    }

    /**
     * Métadonnées du graphique pour alimenter les sélecteurs : les années
     * réellement disponibles (étendue `MIN`/`MAX` des `notes` d'appel —
     * portable MySQL/SQLite — étendue à l'année courante) et la sélection
     * courante echoée depuis la requête.
     *
     * @return array{period: string, year: ?int, month: ?int, years: list<int>}
     */
    private function chartMeta(string $period, ?int $year, ?int $month, ?string $comercialId = null): array
    {
        $query = Note::query()->whereIn('type', self::DASHBOARD_CALL_TYPES);
        if ($comercialId) {
            $query->where('sender_id', $comercialId);
        }

        $range = (array) $query->toBase()
            ->selectRaw('MIN(created_at) as mn, MAX(created_at) as mx')
            ->first();

        $current = (int) Carbon::today()->format('Y');
        $from = ! empty($range['mn']) ? (int) Carbon::parse($range['mn'])->format('Y') : $current;
        $to = max($current, ! empty($range['mx']) ? (int) Carbon::parse($range['mx'])->format('Y') : $current);

        return [
            'period' => $period,
            'year' => $year,
            'month' => $month,
            'years' => range($to, $from),
        ];
    }

    /**
     * Tous les commerciaux pour la carte « Meilleurs employés » du tableau de
     * bord gestionnaire : nombre d'appels passés (notes d'issue, `calls`),
     * nombre d'OUI (`oui`, notes de type YES) et clients convertis distincts.
     * Tri par OUI décroissant, puis par volume d'appels — plus de limite : la
     * carte affiche **tous** les employés (défilement côté client).
     */
    private function topCommercials(): array
    {
        return User::where('role', 'COMERCIAL')->with('employee')->get()
            ->map(function (User $u) {
                $clientsCalled = Note::where('sender_id', $u->id)->calls()->distinct()->count('client_id');
                $clientsOui = Note::where('sender_id', $u->id)->where('type', Note::TYPE_YES)->distinct()->count('client_id');

                return [
                    'id' => $u->id,
                    'name' => trim(($u->employee?->first_name ?? $u->first_name ?? '').' '.($u->employee?->last_name ?? $u->last_name ?? '')) ?: $u->email,
                    'email' => $u->email,
                    'calls' => Note::where('sender_id', $u->id)->calls()->count(),
                    'oui' => Note::where('sender_id', $u->id)->where('type', Note::TYPE_YES)->count(),
                    'reservations' => Reservation::where('comercial_id', $u->id)->count(),
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
