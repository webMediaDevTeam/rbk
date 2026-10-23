<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Filtre de période **global** du tableau de bord (Année → Mois → Semaine →
 * Jour), partagé par `DashboardController` (cartes / graphique / réservations
 * / « Meilleurs employés » / compteurs de liste) et
 * `ProspectOverviewController` (badges KPI de la Grande liste).
 *
 * Règles de normalisation (implicites dans l'UI en cascade, appliquées ici
 * côté serveur pour qu'une requête manuelle ne « tombe » pas) :
 *  - `year` absent ou ≤ 0 : année courante (ou période **désactivée** quand
 *    `defaultToCurrentYear` vaut `false` et qu'aucun paramètre n'est posé) ;
 *  - `month` hors plage `[1, 12]` : « tous les mois » ;
 *  - `week` (semaine du mois, 1 = jours 1–7, la dernière est partielle) :
 *    ignorée sans `month`, remise à « toutes » si hors plage ;
 *  - `day` (jour seul du mois choisi) : ignoré sans `month` ou hors plage ;
 *    quand il existe il **éclipse** `week` (un seul réglage fin à la fois).
 *
 * Fenêtres **roulantes** (`range`) : le paramètre `range` accepte
 * `last{N}days` / `last{N}months` (ex. `last3days`, `last6months`) et pose une
 * fenêtre glissante ancrée sur aujourd'hui, **indépendante** de la cascade
 * Année / Mois / Semaine / Jour. Quand il est présent, il **prime** : les
 * réglages calendaires sont ignorés et les bornes se lisent via
 * `resolveArray()` / `bounds()`.
 */
class PeriodFilter
{
    /**
     * Nombre maximal d'années remontées par un menu de période (voir
     * `yearsBetween()`).
     */
    public const MAX_YEAR_SPAN = 12;

    /**
     * Réglages normalisés lus dans la requête.
     *
     * @return array{year: int, month: ?int, week: ?int, day: ?int}|null
     *               `null` uniquement quand `defaultToCurrentYear` vaut
     *               `false` et qu'**aucun** paramètre de période n'est posé
     *               (le consommateur garde alors son comportement « hors
     *               période », instantané).
     */
    public static function fromRequest(Request $request, bool $defaultToCurrentYear = true): ?array
    {
        // Fenêtre roulante (`range`) : prime sur la cascade calendaire.
        $range = self::rangeFromRequest($request);
        if ($range !== null) {
            return [
                'year' => (int) Carbon::today()->format('Y'),
                'month' => null,
                'week' => null,
                'day' => null,
                'range' => $range,
            ];
        }

        $rawYear = (int) $request->query('year', 0);
        $rawMonth = (int) $request->query('month', 0);
        $rawWeek = (int) $request->query('week', 0);
        $rawDay = (int) $request->query('day', 0);

        if (! $defaultToCurrentYear && $rawYear <= 0 && $rawMonth <= 0 && $rawWeek <= 0 && $rawDay <= 0) {
            return null;
        }

        $year = $rawYear > 0 ? $rawYear : (int) Carbon::today()->format('Y');

        $month = ($rawMonth >= 1 && $rawMonth <= 12) ? $rawMonth : null;

        $week = $rawWeek > 0 ? $rawWeek : null;
        if ($month !== null && $week !== null && $week > self::weeksInMonth($year, $month)) {
            $week = null;
        }

        // Le jour est le réglage le plus fin : hors mois ou hors plage il vaut
        // « tous », et quand il existe il éclipse la semaine.
        $day = $rawDay > 0 ? $rawDay : null;
        if ($month !== null && $day !== null) {
            if ($day > (int) Carbon::createFromDate($year, $month, 1)->endOfMonth()->format('j')) {
                $day = null;
            }
        } else {
            $day = null;
        }

        if ($day !== null) {
            $week = null;
        }

        return ['year' => $year, 'month' => $month, 'week' => $week, 'day' => $day, 'range' => null];
    }

    /**
     * Réglage `range` lu dans la requête : `['days' => N]` pour
     * `last{N}days`, `['months' => N]` pour `last{N}months`, sinon `null`.
     *
     * @return array{days?: int, months?: int}|null
     */
    private static function rangeFromRequest(Request $request): ?array
    {
        $value = strtolower(trim((string) $request->query('range', '')));

        if (preg_match('/^last(\d+)days$/', $value, $m) && (int) $m[1] > 0) {
            return ['days' => (int) $m[1]];
        }

        if (preg_match('/^last(\d+)months$/', $value, $m) && (int) $m[1] > 0) {
            return ['months' => (int) $m[1]];
        }

        return null;
    }

    /**
     * Bornes de la période filtrante + granularité du graphique :
     * année seule = `[1er janvier, 1er janvier N+1)` mensuel ; mois =
     * `[1er, 1er du mois suivant)` journalier ; semaine N du mois =
     * `[jour (N−1)×7+1, min(N×7, dernier jour)]` journalier ; jour =
     * `[jour, lendemain)` journalier (un seul point) et prime sur la semaine.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string} `[début, fin exclusive, granularité]`
     */
    public static function resolve(int $year, ?int $month, ?int $week, ?int $day): array
    {
        if ($month === null) {
            $start = Carbon::createFromDate($year, 1, 1)->startOfDay();

            return [$start, $start->copy()->addYear(), 'month'];
        }

        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfDay();

        if ($day !== null) {
            $start = $monthStart->copy()->addDays($day - 1);

            return [$start, $start->copy()->addDay(), 'day'];
        }

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

    /**
     * Bornes + granularité à partir du réglage normalisé complet — la fenêtre
     * roulante (`range`) prime sur la cascade calendaire.
     *
     * @param  array{year: int, month: ?int, week: ?int, day: ?int, range?: ?array}  $period
     * @return array{0: Carbon, 1: Carbon, 2: string} `[début, fin exclusive, granularité]`
     */
    public static function resolveArray(array $period): array
    {
        $range = $period['range'] ?? null;
        if ($range !== null) {
            return self::resolveRange($range);
        }

        return self::resolve(
            $period['year'],
            $period['month'],
            $period['week'],
            $period['day'],
        );
    }

    /**
     * Fenêtre glissante ancrée sur aujourd'hui : `days` = les N derniers jours
     * (jour courant inclus, granularité journalière), `months` = les N derniers
     * mois calendaires entiers jusqu'au mois courant (granularité mensuelle).
     *
     * @param  array{days?: int, months?: int}  $range
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private static function resolveRange(array $range): array
    {
        if (isset($range['days'])) {
            $days = max((int) $range['days'], 1);
            $start = Carbon::today()->subDays($days - 1)->startOfDay();

            return [$start, Carbon::today()->addDay()->startOfDay(), 'day'];
        }

        $months = max((int) ($range['months'] ?? 1), 1);
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        return [$start, Carbon::today()->startOfMonth()->addMonth(), 'month'];
    }

    /**
     * Bornes `[début, fin)` seules — suffisantes pour borner des compteurs
     * (le graphique a besoin de la granularité en plus, via `resolve()`).
     * Gère la cascade calendaire comme la fenêtre roulante (`resolveArray`).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function bounds(array $period): array
    {
        [$start, $end] = self::resolveArray($period);

        return [$start, $end];
    }

    /** Nombre de semaines du mois (dernière partielle incluse) = ceil(days / 7). */
    private static function weeksInMonth(int $year, int $month): int
    {
        $days = (int) Carbon::createFromDate($year, $month, 1)->endOfMonth()->format('j');

        return (int) ceil($days / 7);
    }

    /**
     * Années couvertes par une plage d'activité, **descendantes** (de la plus
     * récente à la plus ancienne) — de quoi alimenter un menu « Année » sans
     * proposer d'années vides.
     *
     * Utilisé par les fiches employé / entreprise : le menu n'offre que les
     * années où le périmètre a réellement appelé quelqu'un. Les bornes sont lues
     * avec `min()` / `max()` sur la colonne de date — portables sur MySQL comme
     * sur SQLite, contrairement à `YEAR()` / `strftime()`.
     *
     * Sans activité (`from` et `to` nuls) ou dates illisibles : l'année courante
     * seule. La plage est bornée à `MAX_YEAR_SPAN` années pour éviter un menu
     * démesuré sur une base ancienne.
     *
     * @return list<int>
     */
    public static function yearsBetween(mixed $from, mixed $to): array
    {
        $current = (int) Carbon::today()->format('Y');

        try {
            $startYear = $from !== null ? (int) Carbon::parse((string) $from)->format('Y') : $current;
            $endYear = $to !== null ? (int) Carbon::parse((string) $to)->format('Y') : $current;
        } catch (\Throwable) {
            return [$current];
        }

        if ($startYear > $endYear) {
            [$startYear, $endYear] = [$endYear, $startYear];
        }

        $startYear = max($startYear, $endYear - self::MAX_YEAR_SPAN + 1);

        return range($endYear, $startYear);
    }
}
