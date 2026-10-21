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
 */
class PeriodFilter
{
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

        return ['year' => $year, 'month' => $month, 'week' => $week, 'day' => $day];
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
     * Bornes `[début, fin)` seules — suffisantes pour borner des compteurs
     * (le graphique a besoin de la granularité en plus, via `resolve()`).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function bounds(array $period): array
    {
        [$start, $end] = self::resolve(
            $period['year'],
            $period['month'],
            $period['week'],
            $period['day'],
        );

        return [$start, $end];
    }

    /** Nombre de semaines du mois (dernière partielle incluse) = ceil(days / 7). */
    private static function weeksInMonth(int $year, int $month): int
    {
        $days = (int) Carbon::createFromDate($year, $month, 1)->endOfMonth()->format('j');

        return (int) ceil($days / 7);
    }
}
