<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endpoint `GET /api/v1/dashboard/stats` : cartes d'appels de la journée
 * (`stats.today.*` — total, validés, refusés, black-listés) et série du
 * graphique « Évolution journalière des appels » (`?period=`).
 */
class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCommercial(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    private function makeNote(Client $client, User $sender, string $type): Note
    {
        return Note::create([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => $type,
        ]);
    }

    public function test_admin_today_cards_count_call_notes_of_the_current_day(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();
        $client = $this->makeClient();

        // figé : `Carbon::today()` rendrait la date **gelée** par le
        // `travelTo` précédent, pas le jour réel.
        $today = Carbon::today();

        $this->travelTo($today->copy()->setTime(10, 0));
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $commercial, Note::TYPE_NO);
        $this->makeNote($client, $other, Note::TYPE_BLACKLISTED);

        // Hors périmètre « journée » : un OUI de la veille…
        $this->travelTo($today->copy()->subDay()->setTime(15, 0));
        $this->makeNote($client, $commercial, Note::TYPE_YES);

        // …et des notes du jour qui ne sont pas des issues d'appel.
        $this->travelTo($today->copy()->setTime(10, 0));
        $this->makeNote($client, $commercial, Note::TYPE_RESERVED);
        $this->makeNote($client, $commercial, Note::TYPE_NOTE);

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            // 2 OUI + 1 NON + 1 BLACKLISTED : le total inclut l'appel
            // black-listé, la veille et les notes hors appel sont exclues.
            ->assertJsonPath('data.stats.today.calls', 4)
            ->assertJsonPath('data.stats.today.yes', 2)
            ->assertJsonPath('data.stats.today.no', 1)
            ->assertJsonPath('data.stats.today.blacklisted', 1)
            // Chiffres de contexte (cartes non « appel ») toujours servis.
            ->assertJsonPath('data.stats.enterprises', 0)
            ->assertJsonPath('data.stats.commercials', 2)
            ->assertJsonPath('data.stats.prospects', 1);
    }

    public function test_commercial_today_cards_are_scoped_to_own_calls(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();
        $client = $this->makeClient();

        $this->travelTo(Carbon::today()->setTime(10, 0));
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $other, Note::TYPE_NO);
        $this->makeNote($client, $other, Note::TYPE_BLACKLISTED);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.role', 'COMERCIAL')
            ->assertJsonPath('data.stats.today.calls', 1)
            ->assertJsonPath('data.stats.today.yes', 1)
            ->assertJsonPath('data.stats.today.no', 0)
            ->assertJsonPath('data.stats.today.blacklisted', 0)
            ->assertJsonPath('data.top_commercials', []);
    }

    public function test_calls_by_day_supports_week_month_and_year_periods(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        $today = Carbon::today();

        $this->travelTo($today->copy()->setTime(10, 0));
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $commercial, Note::TYPE_NO);

        // Point ancien (2 mois en arrière) : n'apparaît qu'avec `period=year`.
        $this->travelTo($today->copy()->subMonthsNoOverflow(2)->setTime(12, 0));
        $this->makeNote($client, $commercial, Note::TYPE_YES);

        $this->travelTo($today->copy()->setTime(10, 0));
        Sanctum::actingAs($commercial);

        // Semaine (défaut, y compris pour un paramètre inconnu) : 7 points,
        // un par jour, le dernier = aujourd'hui.
        foreach ([null, '?period=week', '?period=nonsense'] as $query) {
            $response = $this->getJson('/api/v1/dashboard/stats'.$query)->assertOk();

            $this->assertCount(7, $response->json('data.calls_by_day'));

            $last = $response->json('data.calls_by_day.6');
            $this->assertSame(Carbon::today()->toDateString(), $last['date']);
            $this->assertSame(2, $last['total']);
            $this->assertSame(1, $last['oui']);
        }

        // Mois : 30 points, toujours un par jour.
        $month = $this->getJson('/api/v1/dashboard/stats?period=month')->assertOk();
        $this->assertCount(30, $month->json('data.calls_by_day'));
        $this->assertSame(2, $month->json('data.calls_by_day.29.total'));

        // Année : 12 points mensuels — le mois courant agrège les 2 appels du
        // jour, celui d'il y a 2 mois son OUI (par clé de mois, pas d'indice).
        $year = $this->getJson('/api/v1/dashboard/stats?period=year')->assertOk();
        $points = $year->json('data.calls_by_day');
        $this->assertCount(12, $points);

        $this->assertSame(Carbon::today()->format('n/Y'), $points[11]['label']);
        $this->assertSame(2, $points[11]['total']);
        $this->assertSame(1, $points[11]['oui']);

        $oldMonth = Carbon::today()->subMonthsNoOverflow(2)->format('Y-m');
        $oldPoint = collect($points)->firstWhere('date', $oldMonth);
        $this->assertNotNull($oldPoint, 'Le mois datant de 2 mois fait partie des 12 points.');
        $this->assertSame(1, $oldPoint['total']);
        $this->assertSame(1, $oldPoint['oui']);
    }

    public function test_calls_by_day_supports_selected_month_and_year(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        // Mois calendaire figé et passé (février 2020, bissextile = 29 jours) :
        // indépendant de la date réelle du test.
        $this->travelTo(Carbon::create(2020, 2, 10, 9, 0));
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $commercial, Note::TYPE_NO);

        $this->travelTo(Carbon::create(2020, 2, 11, 9, 0));
        $this->makeNote($client, $commercial, Note::TYPE_YES);

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // `period=month&year&month` → un point par jour du mois choisi.
        $month = $this->getJson('/api/v1/dashboard/stats?period=month&year=2020&month=2')->assertOk();
        $days = $month->json('data.calls_by_day');

        $this->assertCount(29, $days);
        $this->assertSame('2020-02-01', $days[0]['date']);
        $this->assertSame('2020-02-10', $days[9]['date']);
        $this->assertSame(2, $days[9]['total']);
        $this->assertSame(1, $days[9]['oui']);
        $this->assertSame('2020-02-11', $days[10]['date']);
        $this->assertSame(1, $days[10]['total']);
        $this->assertSame(3, collect($days)->sum('total'));

        // Année calendaire choisie → 12 points mensuels, février agrège les 3.
        $year = $this->getJson('/api/v1/dashboard/stats?period=year&year=2020')->assertOk();
        $points = $year->json('data.calls_by_day');

        $this->assertCount(12, $points);
        $this->assertSame('2020-01', $points[0]['date']);
        $this->assertSame('2020-12', $points[11]['date']);
        $this->assertSame('2020-02', $points[1]['date']);
        $this->assertSame('2/2020', $points[1]['label']);
        $this->assertSame(3, $points[1]['total']);
        $this->assertSame(2, $points[1]['oui']);

        // Métadonnées du sélecteur : l'année 2020 figure parmi les années
        // disponibles (première = année courante, liste descendante).
        $years = $month->json('data.chart.years');
        $this->assertNotEmpty($years);
        $this->assertSame((int) Carbon::today()->format('Y'), $years[0]);
        $this->assertContains(2020, $years);
    }
}
