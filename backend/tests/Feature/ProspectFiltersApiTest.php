<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filtres de la liste de prospects : les valeurs distinctes (municipalité +
 * catégories) proviennent de la table clients, plus de la table categories.
 */
class ProspectFiltersApiTest extends TestCase
{
    use RefreshDatabase;

    private ?Enterprise $enterprise = null;

    private function makeCommercial(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));

        // Périmètre « source » de la Grande liste commerciale : l'employé est
        // rattaché à une entreprise dont la source vaut celle des clients
        // fixtures (`Client::DEFAULT_SOURCE`) — sans entreprise (ou sans
        // source), `GET /clients` est **vide** (verrou serveur, voir
        // GrandeListeSourceFilterTest).
        Employee::create([
            'user_id' => $user->id,
            'enterprise_id' => $this->sharedEnterprise()->id,
            'first_name' => 'Jean',
            'last_name' => 'Test',
        ]);

        return $user;
    }

    private function sharedEnterprise(): Enterprise
    {
        return $this->enterprise ??= Enterprise::create([
            'name' => 'Entreprise test',
            'status' => 'ACTIVE',
            'source' => Client::DEFAULT_SOURCE,
        ]);
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => 'AVAILABLE',
            // La liste commerciale exclut les prospects sans numéro.
            'phone' => '514-555-0100',
        ], $attrs));
    }

    public function test_filters_endpoint_returns_distinct_municipalities_and_categories_from_clients(): void
    {
        $this->makeClient([
            'municipality' => 'Québec',
            'categories' => ['Résidentiel', 'Industriel'],
        ]);
        $this->makeClient([
            'municipality' => 'Lévis',
            'categories' => ['Résidentiel'],
        ]);
        // Valeurs vides / nulles exclues, doublons fusionnés
        $this->makeClient([
            'municipality' => 'Québec',
            'categories' => ['Industriel'],
        ]);
        $this->makeClient([
            'municipality' => null,
            'categories' => null,
        ]);
        $this->makeClient([
            'municipality' => '',
            'categories' => [],
        ]);

        // `/filters` est derrière auth:sanctum (tous les rôles) depuis le
        // déplacement des routes dans le groupe « Authenticated: All roles ».
        Sanctum::actingAs($this->makeCommercial());

        $response = $this->getJson('/api/v1/filters')->assertOk();

        $this->assertSame(['Lévis', 'Québec'], $response->json('data.municipalities'));
        $this->assertSame(['Industriel', 'Résidentiel'], $response->json('data.categories'));
    }

    public function test_clients_list_filters_on_municipality_and_category_label(): void
    {
        $commercial = $this->makeCommercial();

        $target = $this->makeClient([
            'municipality' => 'Québec',
            'categories' => ['Résidentiel'],
        ]);
        $otherMunicipality = $this->makeClient([
            'municipality' => 'Lévis',
            'categories' => ['Résidentiel'],
        ]);
        $otherCategory = $this->makeClient([
            'municipality' => 'Québec',
            'categories' => ['Industriel'],
        ]);

        Sanctum::actingAs($commercial);

        $byMunicipality = collect(
            $this->getJson('/api/v1/clients?municipality='.urlencode('Québec'))
                ->assertOk()
                ->json('data.clients')
        )->pluck('id');

        $this->assertTrue($byMunicipality->contains($target->id));
        $this->assertTrue($byMunicipality->contains($otherCategory->id));
        $this->assertFalse($byMunicipality->contains($otherMunicipality->id));

        $byCategory = collect(
            $this->getJson('/api/v1/clients?category='.urlencode('Résidentiel'))
                ->assertOk()
                ->json('data.clients')
        )->pluck('id');

        $this->assertTrue($byCategory->contains($target->id));
        $this->assertTrue($byCategory->contains($otherMunicipality->id));
        $this->assertFalse($byCategory->contains($otherCategory->id));
    }
}
