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

    /**
     * Le filtre `?category=` fonctionne en **partiel** (`LIKE %clé%`) : une
     * sous-chaîne rattrape tout libellé qui la contient, quelle que soit sa
     * place dans le tableau JSON (élément exact ou fusionné « A , B , C »).
     */
    public function test_category_filter_matches_partial_key(): void
    {
        $plombier = $this->makeClient(['name' => 'Plomba', 'categories' => ['Plomberie']]);
        $plomb2 = $this->makeClient(['name' => 'Plomba2', 'categories' => ['Plombier']]);
        $merged = $this->makeClient(['name' => 'Fusion', 'categories' => ['Maison , Plomberie-Maison']]);
        $unrelated = $this->makeClient(['name' => 'Toit', 'categories' => ['Toiture']]);

        Sanctum::actingAs($this->makeCommercial());

        $ids = collect(
            $this->getJson('/api/v1/clients?category='.urlencode('Plomb'))
                ->assertOk()->json('data.clients')
        )->pluck('id');

        $this->assertTrue($ids->contains($plombier->id), '« Plomb » doit matcher « Plomberie ».');
        $this->assertTrue($ids->contains($plomb2->id), '« Plomb » doit matcher « Plombier ».');
        $this->assertTrue($ids->contains($merged->id), '« Plomb » doit matcher un élément fusionné.');
        $this->assertFalse($ids->contains($unrelated->id), '« Plomb » ne doit pas matcher « Toiture ».');
    }

    /**
     * Éléments `categories` **fusionnés** (« A , B , C » — séparateur « , »
     * avec espace avant la virgule) : le sélecteur `/filters` n'expose que
     * des libellés atomiques sans doublons — les noms légitimes contenant
     * « , » (« Détection, éclairage… ») restent entiers — et le filtre
     * `?category=` retrouve quand même les clients dont le tableau ne porte
     * que l'élément fusionné.
     */
    public function test_merged_category_elements_are_split_for_options_and_still_filterable(): void
    {
        $compound = 'Bâtiments Complexes , CVC-Maisons , Détection, éclairage et installations électriques';

        // Un client avec seulement l'élément fusionné, un autre qui porte
        // aussi le libellé atomique (déduplication des options).
        $this->makeClient(['name' => 'Complexe A', 'categories' => [$compound]]);
        $this->makeClient(['name' => 'Complexe B', 'categories' => [$compound, 'CVC-Maisons']]);

        Sanctum::actingAs($this->makeCommercial());

        $categories = $this->getJson('/api/v1/filters')->assertOk()->json('data.categories');

        $this->assertContains('Bâtiments Complexes', $categories);
        $this->assertContains('CVC-Maisons', $categories);
        $this->assertContains('Détection, éclairage et installations électriques', $categories);
        $this->assertNotContains($compound, $categories);
        // Aucune répétition : chaque libellé n'apparaît qu'une fois.
        $this->assertSame(array_values(array_unique($categories)), $categories);

        // Filtre sur un libellé en **tête** de l'élément fusionné.
        $byHead = collect(
            $this->getJson('/api/v1/clients?category='.urlencode('Bâtiments Complexes'))
                ->assertOk()->json('data.clients')
        )->pluck('name');
        $this->assertEqualsCanonicalizing(['Complexe A', 'Complexe B'], $byHead->all());

        // Filtre sur un libellé au **milieu** (atomique chez B, fusionné chez A).
        $byMiddle = collect(
            $this->getJson('/api/v1/clients?category='.urlencode('CVC-Maisons'))
                ->assertOk()->json('data.clients')
        )->pluck('name');
        $this->assertEqualsCanonicalizing(['Complexe A', 'Complexe B'], $byMiddle->all());

        // Filtre sur un libellé en **fin**, lui-même porteur d'une « , »
        // interne : la correspondance reste un élément entier.
        $byTail = collect(
            $this->getJson('/api/v1/clients?category='.urlencode('Détection, éclairage et installations électriques'))
                ->assertOk()->json('data.clients')
        )->pluck('name');
        $this->assertEqualsCanonicalizing(['Complexe A', 'Complexe B'], $byTail->all());

        // Correspondance **partielle** (`LIKE %clé%`) : une sous-chaîne d'un
        // libellé rattrape désormais les éléments qui la contiennent
        // (« CVC-Maison » matche « CVC-Maisons »).
        $byPartial = collect(
            $this->getJson('/api/v1/clients?category='.urlencode('CVC-Maison'))
                ->assertOk()->json('data.clients') ?? []
        )->pluck('name');
        $this->assertEqualsCanonicalizing(['Complexe A', 'Complexe B'], $byPartial->all());
    }
}
