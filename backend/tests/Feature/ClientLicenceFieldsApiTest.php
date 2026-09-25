<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Colonnes alignées sur le payload n8n (docs/RULES.md §12).
 *
 * Contrat attendu (inchangé par le mapping) :
 *   licence_propre -> licence_propre_numero (INT), numero_licence ->
 *   licence_number, nom_intervenant_entreprise -> intervenant_name,
 *   statut_licence -> licence_status, telephone -> phone,
 *   adresse_complete -> full_address, municipalite -> municipality,
 *   region_administrative -> administrative_region, repondants -> respondents,
 *   categories_sous_categories -> authorized_categories,
 *   cautionnement_compagnie -> cautionnement_compagnie (JSON),
 *   montant_caution -> surety_amount,
 *   date_debut_delivrance -> licence_start_date,
 *   date_fin_paiement_annuel -> licence_end_date.
 */
class ClientLicenceFieldsApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'ACTIVE']);
    }

    /** Client tel que reçu de n8n (voir contrat ci-dessus). */
    private function makeN8nClient(): Client
    {
        return Client::create([
            'name' => 'Jean Dupont', 'enterprise_name' => 'Entreprise Exemple Inc.',
            'status' => 'AVAILABLE',
            'licence_number' => 'LIC-2026-99',
            'licence_propre_numero' => 12345678,
            'intervenant_name' => 'Entreprise Exemple Inc.',
            'licence_status' => 'valide',
            'neq' => '1178901234',
            'full_address' => '123 Rue Principal',
            'municipality' => 'Montréal',
            'administrative_region' => 'Montérégie',
            'phone' => '514-555-0199',
            'respondents' => ['Jean Dupont', 'Marie Curie'],
            'authorized_categories' => ['cat1, 1.2', 'cat88, 5.7'],
            'cautionnement_compagnie' => ['Assurance ABC', 'Courtier XYZ'],
            'surety_company' => 'Assurance ABC',
            'surety_amount' => 10000.00,
            'licence_start_date' => '2026-01-01',
            'licence_end_date' => '2027-01-01',
        ]);
    }

    public function test_new_columns_exist_and_are_stored_as_typed(): void
    {
        $client = $this->makeN8nClient()->fresh();

        $this->assertIsInt($client->licence_propre_numero, 'licence_propre_numero doit être un entier.');
        $this->assertSame(12345678, $client->licence_propre_numero);
        $this->assertSame(['Assurance ABC', 'Courtier XYZ'], $client->cautionnement_compagnie);
        $this->assertIsBool($client->licence_propre, 'L\'ancien booléen licence_propre est conservé.');
        $this->assertSame('Assurance ABC', $client->surety_company, 'L\'ancien string surety_company est conservé.');
    }

    public function test_licence_propre_numero_is_unique(): void
    {
        $this->makeN8nClient();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Client::create([
            'name' => 'Doublon',
            'status' => 'AVAILABLE',
            'licence_propre_numero' => 12345678,
        ]);
    }

    public function test_commercial_client_detail_returns_all_n8n_fields(): void
    {
        $commercial = $this->makeUser('COMERCIAL');
        $client = $this->makeN8nClient();

        Sanctum::actingAs($commercial);

        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client.licence_number', 'LIC-2026-99')
            ->assertJsonPath('data.client.licence_propre_numero', 12345678)
            ->assertJsonPath('data.client.intervenant_name', 'Entreprise Exemple Inc.')
            ->assertJsonPath('data.client.licence_status', 'valide')
            ->assertJsonPath('data.client.neq', '1178901234')
            ->assertJsonPath('data.client.phone', '514-555-0199')
            ->assertJsonPath('data.client.full_address', '123 Rue Principal')
            ->assertJsonPath('data.client.municipality', 'Montréal')
            ->assertJsonPath('data.client.administrative_region', 'Montérégie')
            ->assertJsonPath('data.client.respondents', ['Jean Dupont', 'Marie Curie'])
            ->assertJsonPath('data.client.authorized_categories', ['cat1, 1.2', 'cat88, 5.7'])
            ->assertJsonPath('data.client.cautionnement_compagnie', ['Assurance ABC', 'Courtier XYZ'])
            ->assertJsonPath('data.client.surety_company', 'Assurance ABC')
            ->assertJsonPath('data.client.surety_amount', '10000.00');

        $json = $this->getJson("/api/v1/clients/{$client->id}")->json('data.client');

        $this->assertStringStartsWith('2026-01-01', (string) $json['licence_start_date']);
        $this->assertStringStartsWith('2027-01-01', (string) $json['licence_end_date']);
    }

    public function test_admin_client_detail_returns_the_same_licence_block(): void
    {
        $admin = $this->makeUser('ADMIN');
        $client = $this->makeN8nClient();

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/commercials/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client.licence_number', 'LIC-2026-99')
            ->assertJsonPath('data.client.licence_propre_numero', 12345678)
            ->assertJsonPath('data.client.intervenant_name', 'Entreprise Exemple Inc.')
            ->assertJsonPath('data.client.licence_status', 'valide')
            ->assertJsonPath('data.client.respondents', ['Jean Dupont', 'Marie Curie'])
            ->assertJsonPath('data.client.authorized_categories', ['cat1, 1.2', 'cat88, 5.7'])
            ->assertJsonPath('data.client.cautionnement_compagnie', ['Assurance ABC', 'Courtier XYZ'])
            ->assertJsonPath('data.client.surety_amount', '10000.00');
    }

    public function test_search_matches_licence_propre_numero(): void
    {
        $commercial = $this->makeUser('COMERCIAL');
        $target = $this->makeN8nClient();
        $other = Client::create([
            'name' => 'Autre Inc',
            'status' => 'AVAILABLE',
            'licence_propre_numero' => 87654321,
        ]);

        Sanctum::actingAs($commercial);

        $ids = collect($this->getJson('/api/v1/clients?search=12345678')
            ->assertOk()
            ->json('data.clients'))->pluck('id');

        $this->assertTrue($ids->contains($target->id), 'La licence propre en ENTIER doit être recherchable.');
        $this->assertFalse($ids->contains($other->id), 'Seul le client ciblé doit matcher.');
    }

    public function test_prospect_list_returns_displayed_columns_and_searches_each_of_them(): void
    {
        $commercial = $this->makeUser('COMERCIAL');
        $target = Client::create([
            'name' => 'Jean Dupont', 'enterprise_name' => 'Bâtiments Ltee',
            'status' => 'AVAILABLE',
            'licence_number' => 'LIC-777',
            'neq' => '9876543210',
            'categories' => ['Résidentiel'],
            'authorized_categories' => ['cat42, 9.9'],
            'respondents' => ['Suzanne Répondant'],
        ]);
        $other = Client::create([
            'name' => 'Autre Inc', 'enterprise_name' => 'Autre Groupe',
            'status' => 'AVAILABLE',
        ]);

        Sanctum::actingAs($commercial);

        // Recherche par chacune des colonnes affichées (tableau + cartes).
        $terms = [
            'Bâtiments'    => 'nom d\'entreprise',
            '9876543210'   => 'NEQ',
            'LIC-777'      => 'numéro de licence',
            'Suzanne'      => 'répondants',
            'Résidentiel'  => 'catégorie',
            'cat42'        => 'catégories autorisées',
        ];

        foreach ($terms as $term => $label) {
            $ids = collect($this->getJson('/api/v1/clients?search='.urlencode($term))
                ->assertOk()
                ->json('data.clients'))->pluck('id');

            $this->assertTrue($ids->contains($target->id), "Recherche par {$label} (« {$term} »).");
            $this->assertFalse($ids->contains($other->id), "Seul le client ciblé doit matcher (« {$term} »).");
        }

        // Colonnes affichées présentes dans la réponse de la liste.
        $row = collect($this->getJson('/api/v1/clients?per_page=300')
            ->assertOk()
            ->json('data.clients'))->firstWhere('id', $target->id);

        $this->assertSame('LIC-777', $row['licence_number']);
        $this->assertSame('9876543210', $row['neq']);
        $this->assertSame(['Suzanne Répondant'], $row['respondents']);
        $this->assertSame(['Résidentiel'], $row['categories']);
        $this->assertSame('Bâtiments Ltee', $row['enterprise_name']);
    }

    public function test_per_page_accepts_50_100_200_300_without_silent_clamp(): void
    {
        $commercial = $this->makeUser('COMERCIAL');
        Client::create(['name' => 'Seed', 'status' => 'AVAILABLE']);

        Sanctum::actingAs($commercial);

        foreach ([50, 100, 200, 300] as $perPage) {
            $this->getJson("/api/v1/clients?per_page={$perPage}")
                ->assertOk()
                ->assertJsonPath('data.pagination.per_page', $perPage);
        }
    }

    public function test_admin_prospect_list_returns_the_same_displayed_columns(): void
    {
        $admin = $this->makeUser('ADMIN');
        $commercial = $this->makeUser('COMERCIAL');
        $client = $this->makeN8nClient();

        // La liste admin ne montre que les clients avec historique d'appel.
        \App\Models\CallOutcome::create([
            'client_id' => $client->id,
            'comercial_id' => $commercial->id,
            'outcome' => 'OUI',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/commercials/clients?per_page=300')->assertOk();
        $row = collect($response->json('data.clients'))->firstWhere('id', $client->id);

        $this->assertNotNull($row, 'Le client avec historique doit être listé côté admin.');
        $this->assertSame(300, $response->json('data.pagination.per_page'));
        $this->assertSame('LIC-2026-99', $row['licence_number']);
        $this->assertSame('1178901234', $row['neq']);
        $this->assertSame(['Jean Dupont', 'Marie Curie'], $row['respondents']);
        $this->assertSame(['cat1, 1.2', 'cat88, 5.7'], $row['authorized_categories']);

        // La recherche admin couvre aussi NEQ / licence / répondants / catégories.
        foreach (['1178901234', 'LIC-2026-99', 'Marie Curie', 'cat1'] as $term) {
            $ids = collect($this->getJson('/api/v1/commercials/clients?search='.urlencode($term))
                ->assertOk()
                ->json('data.clients'))->pluck('id');

            $this->assertTrue($ids->contains($client->id), "Recherche admin « {$term} ».");
        }
    }
}
