<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endpoint `GET /api/v1/dashboard/stats` — filtre de période **global**
 * (`?year` / `?month` / `?week`, cartes + graphique + réservations +
 * « Meilleurs employés ») : appels = notes porteuses d'un `call_log_id`
 * créées par le bouton « Appel » RingCentral, OUI = notes d'issue `YES`,
 * échec = appels − OUI. Le graphique rend un point par mois (année seule),
 * par jour du mois (mois choisi) ou par jour de la semaine (1 = jours 1–7).
 * Exception : `stats.blacklisted_clients` = instantané des clients
 * actuellement blacklistés.
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

    /**
     * Note « appel » telle que la crée le bouton « Appel » (RingCentral) :
     * note `TYPE_NOTE` porteuse d'un `call_log_id`, émetteur `SYSTEM`, le
     * journal d'appel rattaché à l'employé qui a appelé (nullable pour les
     * chiffres globaux du gestionnaire).
     */
    private function makeCallNote(Client $client, ?Employee $employee = null): Note
    {
        $call = CallLog::create([
            'employee_id' => $employee?->id,
            'client_id' => $client->id,
            'to_number' => $client->phone,
            'started_at' => now(),
        ]);

        return Note::create([
            'client_id' => $client->id,
            'call_log_id' => $call->id,
            'sender_id' => Note::SENDER_SYSTEM,
            'type' => Note::TYPE_NOTE,
        ]);
    }

    private function makeEmployee(User $user): Employee
    {
        return Employee::create([
            'user_id' => $user->id,
            'enterprise_id' => Enterprise::create(['name' => 'Entreprise A'])->id,
            'first_name' => $user->first_name ?? 'Emp',
            'last_name' => $user->last_name ?? 'LOYÉ',
        ]);
    }

    public function test_admin_cards_count_call_notes_and_oui_for_the_current_year(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        // figé : `Carbon::today()` rendrait la date **gelée** par le
        // `travelTo` précédent, pas le jour réel.
        $today = Carbon::today();

        $this->travelTo($today->copy()->setTime(10, 0));
        // Deux appels cette année (bouton « Appel ») et un OUI ; les autres
        // issues (NON / BLACKLISTED) ne pilotent plus les cartes.
        $this->makeCallNote($client);
        $this->makeCallNote($client);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $commercial, Note::TYPE_NO);
        // Client **actuellement** blacklisté : instantané, hors période.
        $this->makeClient(['name' => 'Banni', 'is_blacklisted' => true]);

        // Hors année : un appel et un OUI de l'année précédente sont exclus.
        $this->travelTo($today->copy()->subYear()->setTime(10, 0));
        $this->makeCallNote($client);
        $this->makeNote($client, $commercial, Note::TYPE_YES);

        $this->travelTo($today->copy()->setTime(10, 0));
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // Filtre par défaut : année courante, mois et semaine « tous ».
        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.stats.period.yes', 1)
            // Échec = appels − OUI.
            ->assertJsonPath('data.stats.period.failed', 1)
            ->assertJsonPath('data.stats.blacklisted_clients', 1)
            ->assertJsonPath('data.chart.month', null)
            ->assertJsonPath('data.chart.week', null)
            // Chiffres de contexte (cartes non « appel ») toujours servis.
            ->assertJsonPath('data.stats.enterprises', 0)
            ->assertJsonPath('data.stats.commercials', 1)
            ->assertJsonPath('data.stats.prospects', 2);
    }

    public function test_commercial_cards_are_scoped_to_own_calls(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();
        $client = $this->makeClient();
        $mine = $this->makeEmployee($commercial);
        $his = $this->makeEmployee($other);

        $this->travelTo(Carbon::today()->setTime(10, 0));
        // 2 appels au mien, 1 OUI ; l'appel et le NON de l'autre sont exclus
        // (la note d'appel est émise par `SYSTEM` : le périmètre passe par
        // l'employé du journal d'appel).
        $this->makeCallNote($client, $mine);
        $this->makeCallNote($client, $mine);
        $this->makeCallNote($client, $his);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->makeNote($client, $other, Note::TYPE_NO);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.role', 'COMERCIAL')
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.stats.period.yes', 1)
            ->assertJsonPath('data.stats.period.failed', 1)
            // Blacklist = instantané global, identique pour le commercial.
            ->assertJsonPath('data.stats.blacklisted_clients', 0)
            ->assertJsonPath('data.top_commercials', []);
    }

    public function test_month_and_week_narrow_every_card(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        $employee = $this->makeEmployee($commercial);

        // Février 2020 (29 jours, bissextile) : dates fixes et passées.
        $this->travelTo(Carbon::create(2020, 1, 15, 10, 0));   // hors mois
        $this->makeCallNote($client, $employee);
        $this->travelTo(Carbon::create(2020, 2, 3, 10, 0));    // semaine 1 (jours 1–7)
        $this->makeCallNote($client, $employee);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->travelTo(Carbon::create(2020, 2, 10, 10, 0));   // semaine 2 (jours 8–14)
        $this->makeCallNote($client, $employee);

        Sanctum::actingAs($commercial);

        // Année seule : les 3 appels de 2020, mois « tous ».
        $this->getJson('/api/v1/dashboard/stats?year=2020')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 3)
            ->assertJsonPath('data.stats.period.yes', 1);

        // Février : 2 appels, sans semaine.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.chart.month', 2)
            ->assertJsonPath('data.chart.week', null);

        // Semaine 1 seule : 1 appel, 1 OUI, 0 échec.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=1')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 1)
            ->assertJsonPath('data.stats.period.yes', 1)
            ->assertJsonPath('data.stats.period.failed', 0)
            ->assertJsonPath('data.chart.week', 1);

        // Semaine hors plage (février 2020 = 5 semaines ; `?week=9` ignoré) :
        // on retrouve le mois entier — la cascade de remise à « toutes » est
        // côté UI, le serveur ne fait pas tomber la requête.
        $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=9')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 2)
            ->assertJsonPath('data.chart.week', null);

        // `week` sans `month` : ignoré, l'année entière reste filtrante.
        $this->getJson('/api/v1/dashboard/stats?year=2020&week=1')
            ->assertOk()
            ->assertJsonPath('data.stats.period.calls', 3);
    }

    public function test_chart_is_monthly_for_a_year_and_daily_for_a_month_or_week(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        $employee = $this->makeEmployee($commercial);

        $this->travelTo(Carbon::create(2020, 2, 10, 9, 0));
        $this->makeCallNote($client, $employee);
        $this->makeNote($client, $commercial, Note::TYPE_YES);
        $this->travelTo(Carbon::create(2020, 2, 11, 9, 0));
        $this->makeCallNote($client, $employee);

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // Année seule : 12 points mensuels, février = 2 appels / 1 OUI ;
        // « Meilleurs employés » suit la même période.
        $year = $this->getJson('/api/v1/dashboard/stats?year=2020')->assertOk();
        $points = $year->json('data.calls_by_day');

        $this->assertCount(12, $points);
        $this->assertSame('2020-01', $points[0]['date']);
        $this->assertSame(0, $points[0]['total']);
        $this->assertSame('2020-02', $points[1]['date']);
        $this->assertSame(2, $points[1]['total']);
        $this->assertSame(1, $points[1]['oui']);
        $this->assertSame(2, $year->json('data.top_commercials.0.calls'));
        $this->assertSame(1, $year->json('data.top_commercials.0.oui'));

        // Mois choisi : un point par jour (29 en 2020, bissextile).
        $days = $this->getJson('/api/v1/dashboard/stats?year=2020&month=2')
            ->assertOk()
            ->json('data.calls_by_day');

        $this->assertCount(29, $days);
        $this->assertSame('2020-02-01', $days[0]['date']);
        $this->assertSame('2020-02-10', $days[9]['date']);
        $this->assertSame(1, $days[9]['total']);
        $this->assertSame(1, $days[9]['oui']);
        $this->assertSame(2, collect($days)->sum('total'));

        // Semaine 2 (jours 8–14) : 7 points du 8 au 14, les 2 appels dedans.
        $week = $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=2')
            ->assertOk()
            ->json('data.calls_by_day');

        $this->assertCount(7, $week);
        $this->assertSame('2020-02-08', $week[0]['date']);
        $this->assertSame('2020-02-14', $week[6]['date']);
        $this->assertSame(2, collect($week)->sum('total'));

        // Dernière semaine partielle (jours 29–29) : 1 point.
        $last = $this->getJson('/api/v1/dashboard/stats?year=2020&month=2&week=5')
            ->assertOk()
            ->json('data.calls_by_day');

        $this->assertCount(1, $last);
        $this->assertSame('2020-02-29', $last[0]['date']);
    }

    public function test_year_selection_stays_in_the_years_list_even_without_data(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // Aucune donnée : le sélecteur doit quand même rendre l'année
        // courante et l'année choisie (ici 2019, avant l'année courante).
        $current = (int) Carbon::today()->format('Y');
        $response = $this->getJson('/api/v1/dashboard/stats?year=2019')->assertOk();
        $years = $response->json('data.chart.years');

        $this->assertContains($current, $years);
        $this->assertContains(2019, $years);
        $response->assertJsonPath('data.chart.year', 2019);
    }
}
