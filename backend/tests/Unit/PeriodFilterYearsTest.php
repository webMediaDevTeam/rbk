<?php

namespace Tests\Unit;

use App\Support\PeriodFilter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `PeriodFilter::yearsBetween()` — menu « Année » des fiches employé et
 * entreprise : ne proposer que les années où le périmètre a réellement appelé.
 *
 * Choix de conception verrouillés ici :
 *  - lecture `min()` / `max()` **en PHP** (les dates arrivent en chaîne, pas via
 *    `YEAR()` / `strftime()`) → portable MySQL comme SQLite in-memory ;
 *  - ordre **descendant** (année la plus récente en tête) ;
 *  - plage inversée ou vide tolérées ;
 *  - plafond `MAX_YEAR_SPAN` pour ne pas rendre un menu démesuré sur une base
 *    ancienne ;
 *  - aucune activité → l'année courante seule (menu jamais vide).
 */
class PeriodFilterYearsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Année de référence fixe : les attendus ne dépendent pas de la date du
        // jour (ni d'un passage de nouvelle année pendant la suite).
        $this->travelTo(Carbon::create(2026, 5, 20, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_returns_activity_years_descending(): void
    {
        $this->assertSame(
            [2026, 2025, 2024],
            PeriodFilter::yearsBetween('2024-02-01 08:00:00', '2026-01-15 08:00:00'),
        );
    }

    public function test_a_single_activity_year_is_returned_alone(): void
    {
        $this->assertSame(
            [2026],
            PeriodFilter::yearsBetween('2026-01-02 08:00:00', '2026-12-30 08:00:00'),
        );
    }

    public function test_inverted_bounds_are_treated_as_a_range(): void
    {
        $this->assertSame(
            [2026, 2025, 2024],
            PeriodFilter::yearsBetween('2026-01-15 08:00:00', '2024-02-01 08:00:00'),
        );
    }

    public function test_no_activity_falls_back_to_the_current_year(): void
    {
        $this->assertSame([2026], PeriodFilter::yearsBetween(null, null));
        $this->assertSame([2026], PeriodFilter::yearsBetween('2026-03-03 08:00:00', null));
        $this->assertSame([2026], PeriodFilter::yearsBetween(null, '2026-03-03 08:00:00'));
    }

    public function test_unreadable_dates_fall_back_to_the_current_year(): void
    {
        $this->assertSame([2026], PeriodFilter::yearsBetween('—', 'pas une date'));
    }

    public function test_the_span_is_capped_so_the_menu_stays_readable(): void
    {
        $years = PeriodFilter::yearsBetween('2001-01-01 08:00:00', '2026-01-01 08:00:00');

        $this->assertCount(PeriodFilter::MAX_YEAR_SPAN, $years);
        $this->assertSame(2026, $years[0]);
        $this->assertSame(2026 - PeriodFilter::MAX_YEAR_SPAN + 1, end($years));
    }

    public function test_a_datetime_object_is_accepted_as_well_as_a_string(): void
    {
        $this->assertSame(
            [2026, 2025],
            PeriodFilter::yearsBetween(Carbon::create(2025, 6, 1), Carbon::create(2026, 6, 1)),
        );
    }
}
