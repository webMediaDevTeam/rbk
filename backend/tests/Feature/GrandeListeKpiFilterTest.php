<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les **badges KPI** de la Grande liste (`GET /clients/overview`) suivent les
 * filtres de la liste : recherche, municipalité, région, catégorie, « Employé »
 * et « source ». Invariant §9 : compteur du badge = lignes rendues **sous
 * filtres**. En revanche, le filtre « Entreprise » est **ignoré** par
 * l'overview — côté UI il ne borne que la liste déroulante « Employé » et ne
 * filtre pas les clients (donc pas les compteurs).
 */
class GrandeListeKpiFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        Sanctum::actingAs($this->admin);
    }

    private function makeEnterprise(): Enterprise
    {
        return Enterprise::create(['name' => 'Bâtiment '.uniqid(), 'status' => 'ACTIVE']);
    }

    private function makeCommercial(Enterprise $enterprise): User
    {
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Employee::create([
            'user_id' => $user->id,
            'enterprise_id' => $enterprise->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        return $user;
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Prospect',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    private function makeNote(Client $client, User $sender): Note
    {
        return Note::create([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => Note::TYPE_YES,
        ]);
    }

    /** `data.prospects.system` de l'overview (total du badge « Tous »). */
    private function systemCount(array $query = []): int
    {
        $url = '/api/v1/clients/overview'.($query === [] ? '' : '?'.http_build_query($query));

        return (int) $this->getJson($url)->assertOk()->json('data.prospects.system');
    }

    public function test_search_filter_narrows_the_kpi_counts(): void
    {
        $this->makeClient(['name' => 'Alpha Construction']);
        $this->makeClient(['name' => 'Beta Plomberie']);

        $this->assertSame(2, $this->systemCount());
        $this->assertSame(1, $this->systemCount(['search' => 'Alpha']));
    }

    public function test_municipality_and_region_filter_the_kpi_counts(): void
    {
        $this->makeClient(['municipality' => 'Québec', 'administrative_region' => 'Capitale-Nationale']);
        $this->makeClient(['municipality' => 'Lévis', 'administrative_region' => 'Chaudière-Appalaches']);

        $this->assertSame(1, $this->systemCount(['municipality' => 'Québec']));
        $this->assertSame(1, $this->systemCount(['administrative_region' => 'Chaudière-Appalaches']));
        // Combinaison incohérente → 0 (ET des critères).
        $this->assertSame(0, $this->systemCount([
            'municipality' => 'Québec',
            'administrative_region' => 'Chaudière-Appalaches',
        ]));
    }

    public function test_available_badge_count_follows_the_region_filter(): void
    {
        $this->makeClient(['municipality' => 'Montréal', 'administrative_region' => 'Montréal']);
        $this->makeClient(['municipality' => 'Sherbrooke', 'administrative_region' => 'Estrie']);

        $badges = $this->getJson('/api/v1/clients/overview?administrative_region=Montréal')
            ->assertOk()
            ->json('data.by_display_status');

        $this->assertSame(1, (int) $badges[Client::STATUS_AVAILABLE]);
    }

    public function test_employee_filter_narrows_the_kpi_counts(): void
    {
        $enterprise = $this->makeEnterprise();
        $alice = $this->makeCommercial($enterprise);
        $bob = $this->makeCommercial($enterprise);

        $aliceClient = $this->makeClient(['name' => 'Client Alice']);
        $this->makeClient(['name' => 'Client Bob']);
        $this->makeNote($aliceClient, $alice);

        // Sans employé : les 2 clients. Avec Alice : le seul qu'elle a noté.
        $this->assertSame(2, $this->systemCount());
        $this->assertSame(1, $this->systemCount(['employee_id' => $alice->id]));
        $this->assertSame(0, $this->systemCount(['employee_id' => $bob->id]));
    }

    public function test_enterprise_filter_is_ignored_by_the_overview(): void
    {
        $enterprise = $this->makeEnterprise();
        $alice = $this->makeCommercial($enterprise);

        $client = $this->makeClient();
        $this->makeNote($client, $alice);
        $this->makeClient(['name' => 'Jamais appelé']);

        // « Entreprise » ne borne plus que la liste déroulante « Employé » :
        // l'overview ne le répercute pas sur les compteurs → total inchangé.
        $this->assertSame(2, $this->systemCount(['enterprise_id' => $enterprise->id]));
    }
}
