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
 * Filtres « Entreprise » / « Employé » de la Grande liste admin, adossés à
 * l'endpoint d'options `GET /clients/interaction-filters`.
 *
 * Le lien client ↔ employé passe par les notes (`notes.sender_id` = id
 * utilisateur), l'employé étant rattaché à son entreprise via
 * `employees.enterprise_id`. Seuls les commerciaux ayant **interagi au moins
 * une fois** (≥ 1 note) sont proposés.
 */
class GrandeListeInteractionFilterTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- Fixtures

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
    }

    private function makeEnterprise(): Enterprise
    {
        return Enterprise::create(['name' => 'Bâtiment '.uniqid(), 'status' => 'ACTIVE']);
    }

    private function makeCommercial(?Enterprise $enterprise = null): User
    {
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);

        if ($enterprise) {
            Employee::create([
                'user_id' => $user->id,
                'enterprise_id' => $enterprise->id,
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ]);
        }

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

    /** Une note d'appel (OUI) émise par `$sender` sur `$client`. */
    private function makeNote(Client $client, User $sender): Note
    {
        return Note::create([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => Note::TYPE_YES,
        ]);
    }

    /** Ids rendus par `GET commercials/clients` (Grande liste admin). */
    private function adminListIds(User $admin, array $query = []): array
    {
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/commercials/clients?'.http_build_query(
            array_merge(['per_page' => 300], $query)
        ))->assertOk();

        return collect($response->json('data.clients'))->pluck('id')->all();
    }

    // -------------------------------------- Endpoint : options d'interaction

    public function test_interaction_filters_only_list_employees_who_interacted(): void
    {
        $enterprise = $this->makeEnterprise();
        $active = $this->makeCommercial($enterprise);
        $idle = $this->makeCommercial($enterprise); // aucun appel → jamais proposé
        $admin = $this->makeAdmin();

        // Une note d'un ADMIN (rôle non COMERCIAL) ne doit pas remonter.
        $this->makeNote($this->makeClient(), $admin);
        $this->makeNote($this->makeClient(), $active);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/clients/interaction-filters')->assertOk();

        $employeeIds = collect($response->json('data.employees'))->pluck('id')->all();
        $this->assertContains($active->id, $employeeIds);
        $this->assertNotContains($idle->id, $employeeIds);
        $this->assertNotContains($admin->id, $employeeIds);

        // L'employé expose bien son `enterprise_id`, et l'entreprise associée
        // figure dans la liste.
        $row = collect($response->json('data.employees'))->firstWhere('id', $active->id);
        $this->assertSame($enterprise->id, $row['enterprise_id']);
        $this->assertContains($enterprise->id, collect($response->json('data.enterprises'))->pluck('id')->all());
    }

    public function test_interaction_filters_exclude_enterprises_without_interacting_employee(): void
    {
        $used = $this->makeEnterprise();
        $empty = $this->makeEnterprise();
        $commercial = $this->makeCommercial($used);
        $admin = $this->makeAdmin();

        $this->makeNote($this->makeClient(), $commercial);

        Sanctum::actingAs($admin);
        $ids = collect($this->getJson('/api/v1/clients/interaction-filters')
            ->assertOk()
            ->json('data.enterprises'))->pluck('id')->all();

        $this->assertContains($used->id, $ids);
        $this->assertNotContains($empty->id, $ids);
    }

    // ------------------------------------------------- Filtre : `employee_id`

    public function test_list_filters_on_the_selected_employee(): void
    {
        $enterprise = $this->makeEnterprise();
        $alice = $this->makeCommercial($enterprise);
        $bob = $this->makeCommercial($enterprise);
        $admin = $this->makeAdmin();

        $aliceClient = $this->makeClient();
        $bobClient = $this->makeClient();
        $untouched = $this->makeClient();

        $this->makeNote($aliceClient, $alice);
        $this->makeNote($bobClient, $bob);

        $this->assertSame(
            [$aliceClient->id],
            $this->adminListIds($admin, ['employee_id' => $alice->id]),
        );
        // Aucun employé = tous les clients (y compris jamais appelés).
        $this->assertContains($untouched->id, $this->adminListIds($admin));
    }

    // ------------------------------------------------ Filter : `enterprise_id`

    public function test_list_filters_on_the_selected_enterprise(): void
    {
        $first = $this->makeEnterprise();
        $second = $this->makeEnterprise();
        $alice = $this->makeCommercial($first);
        $carol = $this->makeCommercial($second);
        $admin = $this->makeAdmin();

        $firstClient = $this->makeClient();
        $secondClient = $this->makeClient();

        $this->makeNote($firstClient, $alice);
        $this->makeNote($secondClient, $carol);

        // Entreprise 1 → uniquement les clients appelés par un de ses employés.
        $this->assertSame(
            [$firstClient->id],
            $this->adminListIds($admin, ['enterprise_id' => $first->id]),
        );
    }

    public function test_enterprise_and_employee_filters_combine(): void
    {
        $enterprise = $this->makeEnterprise();
        $alice = $this->makeCommercial($enterprise);
        $bob = $this->makeCommercial($enterprise);
        $admin = $this->makeAdmin();

        $aliceClient = $this->makeClient();
        $bobClient = $this->makeClient();

        $this->makeNote($aliceClient, $alice);
        $this->makeNote($bobClient, $bob);

        // Entreprise + employé de cette entreprise : intersection cohérente.
        $this->assertSame([$aliceClient->id], $this->adminListIds($admin, [
            'enterprise_id' => $enterprise->id,
            'employee_id' => $alice->id,
        ]));

        // Employé étranger à l'entreprise sélectionnée → vide (ET des critères).
        $this->assertSame([], $this->adminListIds($admin, [
            'enterprise_id' => $this->makeEnterprise()->id,
            'employee_id' => $bob->id,
        ]));
    }

    public function test_commercial_cannot_use_the_admin_endpoint(): void
    {
        $commercial = $this->makeCommercial($this->makeEnterprise());

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/commercials/clients?employee_id='.$commercial->id)
            ->assertForbidden();
    }
}
