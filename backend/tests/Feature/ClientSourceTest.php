<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Origine du prospect : colonne `clients.source` (**répertoire `sources`,
 * sans rapport avec RingCentral**), sans aucune saisie en UI.
 *
 * Contrat (docs/RULES.md §12) :
 *   - `NOT NULL DEFAULT 'Affaire'` → tout prospect créé sans source porte
 *     « Affaire » (lignes existantes comprises à la migration) ;
 *   - le payload n8n peut forcer la valeur avec la clé « Source » ;
 *   - clé absente ou vide → la valeur en place est **conservée** (jamais de
 *     NULL dans la colonne) ;
 *   - la valeur est exposée par les réponses clients (liste + détail).
 */
class ClientSourceTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/clients/bulk-upsert';

    protected function setUp(): void
    {
        parent::setUp();

        // Webhooks publics protégés : en-tête X-Api-Key (VerifyExternalSystemKey).
        $this->withHeaders(['X-Api-Key' => (string) config('services.external_system.key')]);
    }

    // ------------------------------------------------------------- Défaut

    public function test_client_created_without_source_gets_the_affaire_default(): void
    {
        $client = Client::create([
            'name' => 'Prospect sans source',
            'status' => Client::STATUS_AVAILABLE,
        ])->fresh();

        $this->assertSame(Client::DEFAULT_SOURCE, $client->source);
        $this->assertSame('Affaire', $client->source);
    }

    public function test_import_without_source_creates_a_client_with_the_default(): void
    {
        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'L-5001', "Nom de l'intervenant / Entreprise" => 'ACME Construction'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 0);

        $client = Client::where('licence_number', 'L-5001')->firstOrFail();

        $this->assertSame('Affaire', $client->source);
    }

    // --------------------------------------------------------- Valeur payload

    public function test_import_stores_the_source_sent_by_the_payload(): void
    {
        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'L-5002', "Nom de l'intervenant / Entreprise" => 'Bâtiment Plus', 'Source' => 'Angalis'],
            // Clé en minuscules : les clés du payload sont comparées sans
            // casse (`Source` ≡ `source` ≡ `SOURCE`).
            ['Licence' => 'L-5003', "Nom de l'intervenant / Entreprise" => 'Autre Inc', 'source' => 'Affaire'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.created', 2);

        $this->assertSame('Angalis', Client::where('licence_number', 'L-5002')->firstOrFail()->source);
        $this->assertSame('Affaire', Client::where('licence_number', 'L-5003')->firstOrFail()->source);
    }

    public function test_import_empty_source_never_stores_null(): void
    {
        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'L-5004', 'Source' => ''],
        ]])->assertOk()->assertJsonPath('data.created', 1);

        $client = Client::where('licence_number', 'L-5004')->firstOrFail();

        // Chaîne vide → NULL normalisé → clé retirée → défaut « Affaire ».
        $this->assertSame('Affaire', $client->source);

        // Resync avec « Source » vide sur un prospect déjà « Angalis » :
        // la valeur en place est conservée, jamais NULL.
        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'L-5004', 'Source' => '   ', "Nom de l'intervenant / Entreprise" => 'Renommé'],
        ]])->assertOk()->assertJsonPath('data.updated', 1);

        $client->refresh();

        $this->assertSame('Affaire', $client->source);
        $this->assertSame('Renommé', $client->name);
    }

    public function test_import_keeps_the_source_when_omitted_and_changes_it_when_provided(): void
    {
        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'L-5005', 'Source' => 'Angalis'],
        ]])->assertOk();

        $client = Client::where('licence_number', 'L-5005')->firstOrFail();
        $this->assertSame('Angalis', $client->source);

        // Resync sans « Source » : le champ n'est pas dans le payload, la
        // valeur en place survit (pas de remise à « Affaire »).
        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'L-5005', 'Municipalité' => 'Lévis'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame('Angalis', $client->fresh()->source);
        $this->assertSame('Lévis', $client->fresh()->municipality);

        // Resync avec une nouvelle « Source » : la valeur change.
        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'L-5005', 'Source' => 'Affaire'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame('Affaire', $client->fresh()->source);
    }

    public function test_query_source_is_used_when_payload_has_no_source(): void
    {
        $this->postJson(self::URI.'?source=Anglais', ['clients' => [
            ['Licence' => 'L-5007', "Nom de l'intervenant / Entreprise" => 'Ontario Builder'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertSame('Anglais', Client::where('licence_number', 'L-5007')->firstOrFail()->source);
    }

    // ------------------------------------------------------------- Exposition

    public function test_source_is_exposed_by_the_client_endpoints(): void
    {
        $commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        $admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);

        $this->postJson(self::URI, ['clients' => [
            // Numéro obligatoire : la liste commerciale exclut les prospects
            // sans téléphone (statut dérivé `SANS_TELEPHONE`).
            ['Licence' => 'L-5006', 'Source' => 'Angalis', 'Téléphone' => '418-555-0106'],
        ]])->assertOk();

        $client = Client::where('licence_number', 'L-5006')->firstOrFail();

        Sanctum::actingAs($commercial);

        // Détail commercial (`formatClient`).
        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client.source', 'Angalis');

        // Grande liste commerciale.
        $row = collect($this->getJson('/api/v1/clients?per_page=300')
            ->assertOk()
            ->json('data.clients'))->firstWhere('id', $client->id);

        $this->assertSame('Angalis', $row['source'] ?? null);

        // Détail + liste admin.
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/commercials/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client.source', 'Angalis');

        $adminRow = collect($this->getJson('/api/v1/commercials/clients?per_page=300')
            ->assertOk()
            ->json('data.clients'))->firstWhere('id', $client->id);

        $this->assertSame('Angalis', $adminRow['source'] ?? null);
    }
}
