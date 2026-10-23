<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filtre de **période** des deux fiches qui affichent un historique de clients
 * appelés : `GET /commercials/{id}` (onglet « Historique » de la fiche employé)
 * et `GET /entreprises/{id}/stats` (onglet « Historique » de la fiche
 * entreprise).
 *
 * Contrat verrouillé ici :
 *  - la période se lit sur la **date de la note d'appel** du périmètre ;
 *  - **absente = instantané complet** (comportement d'avant le filtre) ;
 *  - posée, elle borne la liste **et** ses badges — les deux sortent de la même
 *    base (`historyBase()`) → invariant RULES §9 « compteur du badge = lignes
 *    rendues » **sous période** ;
 *  - la fenêtre roulante (`range`) prime sur la cascade calendaire ;
 *  - le « dernier appel » affiché par ligne reste dans la fenêtre ;
 *  - le menu d'années (`historique.years`) se lit **hors période** ;
 *  - les agrégats de la fiche (`analytics`, chiffres par employé) ne bougent pas.
 */
class HistoryPeriodFilterTest extends TestCase
{
    use RefreshDatabase;

    /** Référence fixe : milieu de mois, milieu d'année → aucun bord calendaire piégé. */
    private const TODAY = '2026-05-20 12:00:00';

    private int $year;

    private int $lastYear;

    /** Séquence des clients créés, pour des fiches faciles à distinguer. */
    private int $seq = 0;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY));

        $this->year = Carbon::today()->year;
        $this->lastYear = $this->year - 1;

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->commercial = User::factory()->create([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
            'email' => 'jane.doe@example.test',
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * Client appelé par `sender` à la date donnée — la date de la note est ce
     * que filtre la période. `AVAILABLE` par défaut : le badge éponyme reste
     * comparable aux lignes rendues.
     */
    private function callOn(
        User $sender,
        string $at,
        string $type = Note::TYPE_NO,
        string $status = Client::STATUS_AVAILABLE,
    ): Client {
        $client = Client::create([
            'name' => 'Client '.(++$this->seq),
            'status' => $status,
            'phone' => sprintf('514-555-%04d', $this->seq),
        ]);

        Note::forceCreate([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => $type,
            'created_at' => $at,
        ]);

        return $client;
    }

    /** Rattache un employé à une entreprise (périmètre de `entreprises/{id}/stats`). */
    private function employ(User $user, Enterprise $enterprise): void
    {
        Employee::create([
            'user_id' => $user->id,
            'enterprise_id' => $enterprise->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ]);
    }

    /**
     * Trois appels de l'employé : deux de l'année courante (18 mai, 10
     * janvier), un de l'année précédente (18 mai N-1).
     *
     * @return array{may: string, january: string, last_year: string}
     */
    private function seedThreeCalls(): array
    {
        return [
            'may' => $this->callOn($this->commercial, "{$this->year}-05-18 12:00:00")->id,
            'january' => $this->callOn($this->commercial, "{$this->year}-01-10 12:00:00")->id,
            'last_year' => $this->callOn($this->commercial, "{$this->lastYear}-05-18 12:00:00")->id,
        ];
    }

    /**
     * Identifiants de la liste de clients d'une réponse, triés — l'historique
     * n'a pas d'ordre garanti, ces assertions portent donc sur l'ensemble.
     *
     * @return list<string>
     */
    private function clientIds(TestResponse $response, string $path = 'historique.clients'): array
    {
        $ids = array_map(fn ($row) => $row['id'], $response->json($path) ?? []);

        sort($ids);

        return array_values($ids);
    }

    /** Ensemble trié, pour comparer deux listes sans dépendre de l'ordre. */
    private function sorted(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }

    // ==================================================================
    // Fiche employé — GET /commercials/{id}
    // ==================================================================

    public function test_employee_history_is_whole_instantaneous_without_period(): void
    {
        Sanctum::actingAs($this->admin);
        $this->seedThreeCalls();

        $this->getJson("/api/v1/commercials/{$this->commercial->id}")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 3)
            ->assertJsonPath('historique.badges.prospects.system', 3);
    }

    public function test_year_bounds_employee_list_and_its_badges_together(): void
    {
        Sanctum::actingAs($this->admin);
        $ids = $this->seedThreeCalls();

        $response = $this->getJson("/api/v1/commercials/{$this->commercial->id}?year={$this->year}")
            ->assertOk()
            // Badge « Tous » = lignes rendues (§9), sous période comme sans.
            ->assertJsonPath('historique.pagination.total', 2)
            ->assertJsonPath('historique.badges.prospects.system', 2);

        $this->assertSame(
            $this->sorted([$ids['may'], $ids['january']]),
            $this->clientIds($response),
        );
    }

    public function test_month_week_and_day_narrow_the_employee_window(): void
    {
        Sanctum::actingAs($this->admin);
        $ids = $this->seedThreeCalls();

        $base = "/api/v1/commercials/{$this->commercial->id}";

        // Mois 5 seul → l'appel du 18.
        $this->getJson("{$base}?year={$this->year}&month=5")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 1)
            ->assertJsonPath('historique.clients.0.id', $ids['may']);

        // Semaine 2 de janvier = jours 8–14 → l'appel du 10 tombe dedans.
        $this->getJson("{$base}?year={$this->year}&month=1&week=2")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 1)
            ->assertJsonPath('historique.clients.0.id', $ids['january']);

        // Semaine 3 de janvier = jours 15–21 → rien.
        $this->getJson("{$base}?year={$this->year}&month=1&week=3")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 0);

        // Le jour est le réglage le plus fin : jour 18 → l'appel du 18.
        $this->getJson("{$base}?year={$this->year}&month=5&day=18")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 1)
            ->assertJsonPath('historique.clients.0.id', $ids['may']);

        // Jour posé sans mois = ignoré → retour à l'année entière.
        $this->getJson("{$base}?year={$this->year}&day=18")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 2);
    }

    public function test_rolling_range_primes_over_the_calendar_cascade(): void
    {
        Sanctum::actingAs($this->admin);
        $ids = $this->seedThreeCalls();

        $base = "/api/v1/commercials/{$this->commercial->id}";

        // « Derniers 3 jours » depuis le 20 → fenêtre du 18 au 20 inclus :
        // seulement l'appel du 18. L'année et le mois posés en même temps sont
        // ignorés, la fenêtre roulante prime.
        $response = $this->getJson("{$base}?range=last3days&year={$this->lastYear}&month=5")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 1);
        $this->assertSame([$ids['may']], $this->clientIds($response));

        // « Derniers 6 mois » = déc. N-1 → mai N : les deux appels de l'année
        // courante, pas celui de l'an dernier.
        $this->getJson("{$base}?range=last6months")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 2);

        // Token inconnu = pas de fenêtre roulante : la cascade reprend la main.
        $this->getJson("{$base}?range=last42weeks&year={$this->year}")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 2);

        // `range` seul borne réellement (≠ instantané) : c'est un réglage choisi.
        $this->getJson("{$base}?range=last3days")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 1);
    }

    public function test_last_call_shown_on_a_row_stays_inside_the_window(): void
    {
        Sanctum::actingAs($this->admin);

        // Un seul client, appelé une fois par an : sous période passée, le
        // « dernier appel » affiché doit rester dans la période — sinon la
        // ligne contredit le filtre appliqué à la liste.
        $client = $this->callOn($this->commercial, "{$this->year}-05-18 12:00:00");
        Note::forceCreate([
            'client_id' => $client->id,
            'sender_id' => $this->commercial->id,
            'type' => Note::TYPE_YES,
            'created_at' => "{$this->lastYear}-03-03 09:00:00",
        ]);

        $response = $this->getJson("/api/v1/commercials/{$this->commercial->id}?year={$this->lastYear}")
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', 1)
            ->assertJsonPath('historique.clients.0.id', $client->id)
            ->assertJsonPath('historique.clients.0.last_call.type', Note::TYPE_YES);

        $this->assertStringStartsWith(
            "{$this->lastYear}-03-03",
            (string) $response->json('historique.clients.0.last_call.created_at'),
        );
    }

    public function test_years_menu_covers_activity_outside_the_posed_period(): void
    {
        Sanctum::actingAs($this->admin);
        $this->seedThreeCalls();

        // Le menu ne se rétrécit pas quand une période est déjà posée : les
        // deux années d'activité restent proposées, la plus récente en tête.
        $this->getJson("/api/v1/commercials/{$this->commercial->id}?year={$this->year}")
            ->assertOk()
            ->assertJsonPath('historique.years', [$this->year, $this->lastYear]);
    }

    public function test_employee_badge_counter_equals_rows_under_the_period(): void
    {
        Sanctum::actingAs($this->admin);

        // Deux clients AVAILABLE cette année, un autre l'an dernier : le badge
        // AVAILABLE borné doit égaler les lignes AVAILABLE bornées.
        $this->seedThreeCalls();

        $base = "/api/v1/commercials/{$this->commercial->id}";

        $badge = $this->getJson("{$base}?year={$this->year}")
            ->assertOk()
            ->json('historique.badges.by_display_status.'.Client::STATUS_AVAILABLE);

        $this->assertSame(2, $badge);

        $this->getJson("{$base}?year={$this->year}&status=".Client::STATUS_AVAILABLE)
            ->assertOk()
            ->assertJsonPath('historique.pagination.total', $badge);
    }

    public function test_employee_period_does_not_touch_the_stat_cards(): void
    {
        Sanctum::actingAs($this->admin);
        $this->seedThreeCalls();

        // Les cartes du haut restent des totaux de toute l'histoire : seule la
        // section « Historique » se borne.
        $this->getJson("/api/v1/commercials/{$this->commercial->id}?year={$this->year}&month=5")
            ->assertOk()
            ->assertJsonPath('analytics.calls', 3)
            ->assertJsonPath('analytics.clients_called', 3)
            ->assertJsonPath('historique.pagination.total', 1);
    }

    // ==================================================================
    // Fiche entreprise — GET /entreprises/{id}/stats
    // ==================================================================

    public function test_enterprise_history_period_bounds_list_and_badges_but_not_analytics(): void
    {
        Sanctum::actingAs($this->admin);

        $enterprise = Enterprise::create(['name' => 'Acme Corp', 'status' => 'ACTIVE']);
        $this->employ($this->commercial, $enterprise);

        $colleague = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        $this->employ($colleague, $enterprise);

        // Périmètre entreprise : 3 appels — deux cette année (un par employé),
        // un l'an dernier.
        $may = $this->callOn($this->commercial, "{$this->year}-05-18 12:00:00")->id;
        $january = $this->callOn($colleague, "{$this->year}-01-10 12:00:00")->id;
        // Le troisième appel (an dernier) n'est là que pour être exclu par la
        // période : la comparaison d'ensembles ci-dessous le vérifie.
        $this->callOn($colleague, "{$this->lastYear}-05-18 12:00:00");

        // Hors périmètre : l'appel d'une autre entreprise, la même année.
        $stranger = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        $this->employ($stranger, Enterprise::create(['name' => 'Other Inc', 'status' => 'ACTIVE']));
        $this->callOn($stranger, "{$this->year}-05-18 12:00:00");

        $base = "/api/v1/entreprises/{$enterprise->id}/stats";

        // Instantané (aucune période posée) : les 3 clients de l'entreprise.
        $this->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.historique.pagination.total', 3)
            ->assertJsonPath('data.historique.badges.prospects.system', 3)
            ->assertJsonPath('data.analytics.calls', 3);

        // Année courante : liste **et** badges bornés ensemble.
        $response = $this->getJson("{$base}?year={$this->year}")
            ->assertOk()
            ->assertJsonPath('data.historique.pagination.total', 2)
            ->assertJsonPath('data.historique.badges.prospects.system', 2)
            ->assertJsonPath('data.historique.years', [$this->year, $this->lastYear])
            ->assertJsonPath('data.analytics.calls', 3)
            ->assertJsonPath('data.analytics.clients_called', 3);

        $this->assertSame($this->sorted([$may, $january]), $this->clientIds($response, 'data.historique.clients'));

        // Les agrégats comme les lignes par employé restent des totaux de toute
        // l'histoire : 3 appels répartis entre les deux employés.
        $this->assertSame(3, collect($response->json('data.employees'))->sum('calls'));

        // Fenêtre roulante : prime sur la cascade, comme sur la fiche employé.
        $this->getJson("{$base}?range=last3days&year={$this->lastYear}")
            ->assertOk()
            ->assertJsonPath('data.historique.pagination.total', 1);
    }

    public function test_enterprise_badge_counter_equals_rows_under_the_period(): void
    {
        Sanctum::actingAs($this->admin);

        $enterprise = Enterprise::create(['name' => 'Acme Corp', 'status' => 'ACTIVE']);
        $this->employ($this->commercial, $enterprise);

        $this->callOn($this->commercial, "{$this->year}-05-18 12:00:00");
        $this->callOn($this->commercial, "{$this->lastYear}-05-18 12:00:00");

        $base = "/api/v1/entreprises/{$enterprise->id}/stats";

        $badge = $this->getJson("{$base}?year={$this->year}")
            ->assertOk()
            ->json('data.historique.badges.by_display_status.'.Client::STATUS_AVAILABLE);

        $this->assertSame(1, $badge);

        $this->getJson("{$base}?year={$this->year}&status=".Client::STATUS_AVAILABLE)
            ->assertOk()
            ->assertJsonPath('data.historique.pagination.total', $badge);
    }
}
