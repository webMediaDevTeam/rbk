<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Webhook **public** de suppression en masse
 * (`POST|DELETE /api/v1/clients/bulk-delete`, docs/RULES.md §12).
 *
 * Fil conducteur : **on ne supprime jamais un client qui a des données
 * liées** (réservations / notes / rappels, tables en `cascadeOnDelete`) —
 * l'entrée est ignorée et la boucle passe au client suivant.
 */
class PublicClientBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/clients/bulk-delete';

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // Webhooks publics protégés : en-tête X-Api-Key (VerifyExternalSystemKey).
        $this->withHeaders(['X-Api-Key' => (string) config('services.external_system.key')]);

        $this->commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Prospect',
            'status' => Client::STATUS_AVAILABLE,
        ], $attrs));
    }

    private function rawPost(string $body): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            // `$this->call()` n'applique PAS les defaultHeaders de withHeaders().
            'HTTP_X_API_KEY' => (string) config('services.external_system.key'),
        ], $body);
    }

    // -------------------------------------------------------------- Suppression

    public function test_guest_can_bulk_delete_clients_without_authentication(): void
    {
        $client = $this->makeClient(['licence_number' => 'L-1001']);

        $this->postJson(self::URI, ['licences' => ['L-1001']])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.missing', 0)
            ->assertJsonPath('data.failed', 0);

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_delete_verb_is_supported_as_well_as_post(): void
    {
        $client = $this->makeClient(['licence_number' => 'L-1100']);

        $this->deleteJson(self::URI, ['licences' => ['L-1100']])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    // -------------------------------------------- Données liées : jamais touchées

    public function test_client_with_linked_data_is_skipped_and_the_batch_moves_on(): void
    {
        $linked = $this->makeClient(['licence_number' => 'L-2001']);

        Reservation::create([
            'client_id' => $linked->id,
            'comercial_id' => $this->commercial->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        $clean = $this->makeClient(['licence_number' => 'L-2002']);

        $this->postJson(self::URI, ['licences' => ['L-2001', 'L-2002']])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.skipped_items.0.index', 0)
            ->assertJsonPath('data.skipped_items.0.licence_number', 'L-2001')
            ->assertJsonPath('data.skipped_items.0.linked.reservations', 1)
            ->assertJsonPath('data.skipped_items.0.linked.notes', 0);

        // Le client lié et son historique sont intacts…
        $this->assertDatabaseHas('clients', ['id' => $linked->id]);
        $this->assertDatabaseHas('reservations', ['client_id' => $linked->id]);
        // …et la boucle a bien continué jusqu'au client suivant.
        $this->assertDatabaseMissing('clients', ['id' => $clean->id]);
    }

    public function test_client_with_a_note_is_skipped_too(): void
    {
        $client = $this->makeClient(['licence_number' => 'L-3001']);

        Note::create([
            'client_id' => $client->id,
            'sender_id' => $this->commercial->id,
            'type' => Note::TYPE_YES,
        ]);

        $this->postJson(self::URI, ['licences' => ['L-3001']])
            ->assertOk()
            ->assertJsonPath('data.deleted', 0)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.skipped_items.0.linked.notes', 1);

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('notes', ['client_id' => $client->id]);
    }

    // --------------------------------------------------------- Absents / formes

    public function test_unknown_licences_are_counted_as_missing(): void
    {
        $present = $this->makeClient(['licence_number' => 'L-4001']);

        $this->postJson(self::URI, ['licences' => ['L-4001', 'L-4040']])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.missing', 1)
            ->assertJsonPath('data.missing_items.0.index', 1)
            ->assertJsonPath('data.missing_items.0.licence_number', 'L-4040');

        $this->assertDatabaseMissing('clients', ['id' => $present->id]);
    }

    public function test_bare_list_of_strings_and_payload_objects_are_both_accepted(): void
    {
        $byPropre = $this->makeClient(['licence_number' => 'L-5001', 'licence_propre_numero' => 4242]);
        $byString = $this->makeClient(['licence_number' => 'L-5002']);

        // Objet payload (clés françaises) → repli sur la licence propre.
        $this->rawPost(json_encode(['clients' => [['Licence (propre)' => '4242']]], JSON_UNESCAPED_UNICODE))
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('clients', ['id' => $byPropre->id]);

        // Liste JSON nue de chaînes.
        $this->rawPost(json_encode(['L-5002']))
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('clients', ['id' => $byString->id]);
    }

    // -------------------------------------------------------------- Validation

    public function test_invalid_envelopes_are_rejected_with_422(): void
    {
        $keep = $this->makeClient(['licence_number' => 'L-6001']);

        $this->postJson(self::URI, ['foo' => 'bar'])->assertStatus(422);
        $this->postJson(self::URI, ['licences' => []])->assertStatus(422);
        $this->rawPost('"pas un tableau"')->assertStatus(422);

        // Un corps invalide ne supprime rien.
        $this->assertDatabaseHas('clients', ['id' => $keep->id]);
    }

    public function test_lot_size_is_bounded(): void
    {
        config(['public_api.max_items' => 1]);

        $this->makeClient(['licence_number' => 'A']);
        $this->makeClient(['licence_number' => 'B']);

        $this->postJson(self::URI, ['licences' => ['A', 'B']])->assertStatus(422);

        $this->assertSame(2, Client::count(), 'Un lot trop long est refusé intégralement.');
    }

    // ------------------------------------------------- Cohérence des listes / CORS

    public function test_deletion_refreshes_the_distinct_filter_lists(): void
    {
        $this->makeClient(['licence_number' => 'L-9001', 'municipality' => 'Trois-Rivières']);

        $this->assertContains('Trois-Rivières', Client::distinctValues('municipality'));

        $this->postJson(self::URI, ['licences' => ['L-9001']])->assertOk();

        $this->assertNotContains(
            'Trois-Rivières',
            Client::distinctValues('municipality'),
            'La suppression doit invalider le cache des filtres.'
        );
    }

    public function test_cors_preflight_is_answered_on_the_delete_route(): void
    {
        $response = $this->call('OPTIONS', self::URI, [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertSame('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
