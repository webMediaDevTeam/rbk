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
 * `GET entreprises/{id}/stats` — alimente la fiche `/entreprises/:id`.
 *
 * Verrouille l'**enveloppe** de la réponse (`{success, data: {entreprise,
 * analytics, employees, historique}}`, la même que `GET entreprises`) : le
 * hook `useEntrepriseDetail.js` la déballe, et un décalage (lecture de
 * `data.entreprise` au premier niveau) affichait « Entreprise
 * introuvable. » malgré une réponse HTTP 200.
 */
class EnterpriseStatsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'first_name' => 'Ada',
            'last_name' => 'Admin',
        ]);
    }

    /** Employé COMERCIAL rattaché à l'entreprise (table `employees`). */
    private function commercialFor(Enterprise $enterprise): User
    {
        $commercial = User::factory()->create([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        Employee::create([
            'user_id' => $commercial->id,
            'enterprise_id' => $enterprise->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        return $commercial;
    }

    /** Client + issue d'appel émis par `sender` (note = appel). */
    private function placeCall(User $sender, string $type, string $clientName): Client
    {
        $client = Client::create([
            'name' => $clientName,
            'status' => $type === Note::TYPE_YES ? Client::STATUS_CONFIRMED : Client::STATUS_UNAVAILABLE,
            'phone' => '514-555-0100',
        ]);

        Note::create([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => $type,
        ]);

        return $client;
    }

    public function test_stats_returns_enterprise_analytics_employees_and_common_history(): void
    {
        Sanctum::actingAs($this->admin);

        $enterprise = Enterprise::create(['name' => 'Acme Corp', 'status' => 'ACTIVE']);
        $commercial = $this->commercialFor($enterprise);

        // Hors périmètre : les appels d'une autre entreprise ne comptent pas.
        $other = Enterprise::create(['name' => 'Other Inc', 'status' => 'ACTIVE']);
        $this->placeCall($this->commercialFor($other), Note::TYPE_YES, 'Client hors périmètre');

        // 2 appels de l'employé, sur 2 clients distincts, dont 1 « OUI ».
        $this->placeCall($commercial, Note::TYPE_YES, 'Bâtiment A');
        $this->placeCall($commercial, Note::TYPE_NO, 'Bâtiment B');

        $this->getJson("/api/v1/entreprises/{$enterprise->id}/stats")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entreprise.id', $enterprise->id)
            ->assertJsonPath('data.entreprise.name', 'Acme Corp')
            ->assertJsonPath('data.analytics.employees', 1)
            ->assertJsonPath('data.analytics.calls', 2)
            ->assertJsonPath('data.analytics.calls_oui', 1)
            ->assertJsonPath('data.analytics.clients_called', 2)
            ->assertJsonPath('data.analytics.clients_oui', 1)
            ->assertJsonPath('data.employees.0.id', $commercial->id)
            ->assertJsonPath('data.historique.pagination.total', 2)
            ->assertJsonPath('data.historique.badges.prospects.system', 2);
    }

    /** Contrat du 404 affiché par la page (`errorMessage`). */
    public function test_unknown_enterprise_returns_404_with_message(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/entreprises/01a0edc6-0a44-736e-8c62-6adf57bdc9f3/stats')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Entreprise introuvable.');
    }
}
