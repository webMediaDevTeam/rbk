<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook **public** d'import de prospects
 * (`POST /api/v1/clients/bulk-upsert`, docs/RULES.md §12).
 *
 * Fil conducteur : pas d'authentification, écriture limitée aux colonnes du
 * payload (jamais l'état applicatif), échecs signalés **par ligne** sans
 * casser le reste du lot.
 */
class PublicClientBulkUpsertTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/clients/bulk-upsert';

    // ------------------------------------------------------------- Création

    public function test_guest_can_bulk_upsert_clients_without_authentication(): void
    {
        $response = $this->postJson(self::URI, ['clients' => [
            [
                'Licence' => 'L-1001',
                'Licence (propre)' => '4001',
                "Nom de l'intervenant / Entreprise" => 'ACME Construction',
                'Municipalité' => 'Québec',
                'Téléphone' => '418-555-0001',
                'Répondants / Interlocuteurs (Qualifications)' => ['Jean Dupont', 'Marie Curie'],
                'Catégories et sous-catégories autorisées' => ['cat1, 1.2'],
            ],
            ['Licence' => 'L-1002', "Nom de l'intervenant / Entreprise" => 'Beta inc.'],
        ]]);

        // Aucune authentification : la requête part sans token.
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 2)
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.failed', 0);

        $client = Client::where('licence_number', 'L-1001')->firstOrFail();

        $this->assertSame('ACME Construction', $client->name);
        $this->assertSame('ACME Construction', $client->intervenant_name);
        $this->assertSame('Québec', $client->municipality);
        $this->assertSame(['Jean Dupont', 'Marie Curie'], $client->respondents);
        // Dérivations de l'import (§12) : catégories du filtre + licence propre.
        $this->assertSame(['cat1, 1.2'], $client->categories);
        $this->assertTrue((bool) $client->licence_propre);
        // État applicatif neuf, jamais lu dans le payload.
        $this->assertSame(Client::STATUS_AVAILABLE, $client->status);
        $this->assertFalse((bool) $client->is_blacklisted);
        $this->assertNull($client->returned_at);
    }

    public function test_bare_json_list_is_accepted_as_well_as_the_envelope(): void
    {
        $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([['Licence' => 'L-1100']], JSON_UNESCAPED_UNICODE))
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertSame(1, Client::count());
    }

    // -------------------------------------------------------------- Mise à jour

    public function test_resync_updates_payload_columns_and_never_applicative_state(): void
    {
        $existing = Client::create([
            'licence_number' => 'L-2001',
            'name' => 'Ancien nom',
            'status' => Client::STATUS_RESERVED,
            'is_blacklisted' => false,
            'returned_at' => now()->addDays(5),
        ]);

        $this->postJson(self::URI, ['clients' => [[
            'Licence' => 'L-2001',
            "Nom de l'intervenant / Entreprise" => 'Nouveau nom',
            'Téléphone' => '555',
            // Clés « état applicatif » : hors `PAYLOAD_MAP`, donc ignorées.
            'status' => 'BLACKLISTED',
            'is_blacklisted' => true,
            'returned_at' => '2020-01-01',
        ]]])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 1);

        $existing->refresh();
        $this->assertSame('Nouveau nom', $existing->name);
        $this->assertSame('555', $existing->phone);
        $this->assertSame(Client::STATUS_RESERVED, $existing->status);
        $this->assertFalse((bool) $existing->is_blacklisted);
        $this->assertNotNull($existing->returned_at);
        $this->assertSame(1, Client::count(), 'La resynchronisation ne crée pas de doublon.');
    }

    public function test_identical_item_is_not_rewritten(): void
    {
        $payload = ['Licence' => 'L-3001', "Nom de l'intervenant / Entreprise" => 'Gamma'];

        $this->postJson(self::URI, ['clients' => [$payload]])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $client = Client::where('licence_number', 'L-3001')->firstOrFail();
        // `updateTimestamps()` n'écrase pas un `updated_at` déjà modifié :
        // on gèle la valeur pour prouver qu'aucune réécriture n'a lieu.
        $client->forceFill(['updated_at' => now()->subDay()])->save();
        $frozen = $client->refresh()->updated_at;

        $this->postJson(self::URI, ['clients' => [$payload]])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.unchanged', 1);

        $this->assertTrue(
            $frozen->equalTo($client->refresh()->updated_at),
            'Une resynchronisation identique ne touche pas `updated_at`.'
        );
    }

    public function test_upsert_falls_back_to_licence_propre_numero(): void
    {
        Client::create([
            'licence_number' => 'L-5001',
            'name' => 'Delta',
            'licence_propre_numero' => 77777,
        ]);

        $this->postJson(self::URI, ['clients' => [[
            'Licence (propre)' => '77777',
            'Téléphone' => '999',
        ]]])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 1);

        $client = Client::firstOrFail();
        $this->assertSame(1, Client::count(), 'Le repli sur la licence propre ne crée pas de doublon.');
        $this->assertSame('999', $client->phone);
        $this->assertSame('Delta', $client->name, 'Champ absent du payload : inchangé.');
    }

    // --------------------------------------------------------- Erreurs partielles

    public function test_partial_failures_keep_the_rest_of_the_batch(): void
    {
        $response = $this->postJson(self::URI, ['clients' => [
            ['Municipalité' => 'Sans licence'],   // → échec (clé d'upsert absente)
            ['Licence' => 'L-4001'],              // → traité
        ]]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.errors.0.index', 0);

        $this->assertNull($response->json('data.errors.0.licence_number'));
        $this->assertNotEmpty($response->json('data.errors.0.error'));
        $this->assertSame(1, Client::count(), 'La ligne valide est malgré tout écrite.');
    }

    public function test_batch_where_every_item_fails_reports_success_false(): void
    {
        $this->postJson(self::URI, ['clients' => [
            ['Municipalité' => 'x'],
            ['Municipalité' => 'y'],
        ]])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.failed', 2);

        $this->assertSame(0, Client::count());
    }

    // ------------------------------------------------------------- Validation

    public function test_invalid_envelopes_are_rejected_with_422(): void
    {
        $this->postJson(self::URI, ['foo' => 'bar'])->assertStatus(422);
        $this->postJson(self::URI, ['clients' => []])->assertStatus(422);

        // Corps JSON qui n'est pas un tableau.
        $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '"pas un tableau"')->assertStatus(422);

        $this->assertSame(0, Client::count());
    }

    public function test_lot_size_is_bounded(): void
    {
        config(['public_api.max_items' => 1]);

        $this->postJson(self::URI, ['clients' => [
            ['Licence' => 'A'],
            ['Licence' => 'B'],
        ]])->assertStatus(422);

        $this->assertSame(0, Client::count());
    }

    // ------------------------------------------------------------------ CORS

    public function test_cors_preflight_is_answered_on_the_public_route(): void
    {
        $response = $this->call('OPTIONS', self::URI, [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertSame(
            'http://localhost:5173',
            $response->headers->get('Access-Control-Allow-Origin'),
            'Le preflight CORS doit répondre pour le endpoint public.'
        );
    }
}
