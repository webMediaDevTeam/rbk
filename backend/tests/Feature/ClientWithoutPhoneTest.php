<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\Client\ClientImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Statut dérivé « Sans téléphone » (`Client::STATUS_SANS_TELEPHONE`,
 * RULES §2 / §9) :
 *
 *  - 8e badge + filtre admin (`status=SANS_TELEPHONE`, compteur = lignes) ;
 *  - **exclusion** de la liste commerciale (`ClientSearchService::applyFilters`) ;
 *  - saisie / modification du numéro depuis l'accès Admin
 *    (`PATCH commercials/clients/{id}/phone`) ;
 *  - import n8n qui n'efface **pas** un numéro saisi à la main.
 */
class ClientWithoutPhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // Webhooks publics protégés : en-tête X-Api-Key (VerifyExternalSystemKey).
        $this->withHeaders(['X-Api-Key' => (string) config('services.external_system.key')]);

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    /** Ids rendus par la grande liste admin (`GET commercials/clients`). */
    private function adminListIds(string $query): array
    {
        $rows = $this->getJson('/api/v1/commercials/clients?'.$query)
            ->assertOk()
            ->json('data.clients');

        return collect($rows)->pluck('id')->all();
    }

    // --------------------------------------------------------- Badge « Sans téléphone »

    public function test_overview_counts_clients_without_phone_in_a_derived_bucket(): void
    {
        $this->makeClient();                                // numéro présent
        $this->makeClient(['phone' => null]);               // aucun numéro
        $this->makeClient(['phone' => '']);                 // reliquat de forme texte

        Sanctum::actingAs($this->admin);

        $badges = $this->getJson('/api/v1/clients/overview')
            ->assertOk()
            ->json('data.by_display_status');

        $this->assertArrayHasKey(Client::STATUS_SANS_TELEPHONE, $badges, 'Le 8e badge existe.');
        $this->assertSame(2, (int) $badges[Client::STATUS_SANS_TELEPHONE]);

        // Le seau dérivé **recoupe** les autres : les deux clients sans
        // numéro sont aussi `AVAILABLE`.
        $this->assertSame(3, (int) $badges[Client::STATUS_AVAILABLE]);
    }

    public function test_admin_filter_renders_exactly_the_clients_without_phone(): void
    {
        $withPhone = $this->makeClient();
        $noPhone = $this->makeClient(['phone' => null]);
        $emptyPhone = $this->makeClient(['phone' => '']);

        Sanctum::actingAs($this->admin);

        $badge = (int) $this->getJson('/api/v1/clients/overview')
            ->assertOk()
            ->json('data.by_display_status.'.Client::STATUS_SANS_TELEPHONE);

        $ids = $this->adminListIds('status='.Client::STATUS_SANS_TELEPHONE);

        $this->assertEqualsCanonicalizing([$noPhone->id, $emptyPhone->id], $ids);
        $this->assertNotContains($withPhone->id, $ids);
        $this->assertSame(2, $badge, 'Compteur du badge = lignes rendues.');
    }

    // ------------------------------------------------- Visibilité côté Commercial

    public function test_commercial_cannot_see_clients_without_phone(): void
    {
        $withPhone = $this->makeClient();
        $noPhone = $this->makeClient(['phone' => null]);

        Sanctum::actingAs($this->commercial);

        $ids = collect($this->getJson('/api/v1/clients')
            ->assertOk()
            ->json('data.clients'))->pluck('id')->all();

        $this->assertContains($withPhone->id, $ids);
        $this->assertNotContains($noPhone->id, $ids, 'Client sans numéro invisible pour le commercial.');

        // Le lot réservé obéit au **même** scope : seul le prospect muni
        // d'un numéro entre dans la liste.
        $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertCreated()
            ->assertJsonPath('data.reserved', 1);

        $this->assertSame(Client::STATUS_AVAILABLE, $noPhone->fresh()->status);
        $this->assertSame(Client::STATUS_RESERVED, $withPhone->fresh()->status);
    }

    // --------------------------------------------------- Saisie du numéro (Admin)

    public function test_admin_can_add_or_change_a_client_phone_number(): void
    {
        $client = $this->makeClient(['phone' => null]);

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/commercials/clients/{$client->id}/phone", [
            'phone' => '514 353 5820',
        ])
            ->assertOk()
            ->assertJsonPath('data.client.phone', '514-353-5820');

        $this->assertSame('514-353-5820', $client->fresh()->phone);

        // Le client sort du seau « Sans téléphone » dans la foulée.
        $this->assertSame(
            0,
            (int) $this->getJson('/api/v1/clients/overview')
                ->assertOk()
                ->json('data.by_display_status.'.Client::STATUS_SANS_TELEPHONE)
        );

        // Remplacement puis retrait du numéro.
        $this->patchJson("/api/v1/commercials/clients/{$client->id}/phone", [
            'phone' => '8194186550',
        ])->assertOk()->assertJsonPath('data.client.phone', '819-418-6550');

        $this->patchJson("/api/v1/commercials/clients/{$client->id}/phone", [
            'phone' => null,
        ])->assertOk()->assertJsonPath('data.client.phone', null);

        $this->assertNull($client->fresh()->phone);
    }

    public function test_phone_endpoint_is_forbidden_to_commercial(): void
    {
        $client = $this->makeClient(['phone' => null]);

        Sanctum::actingAs($this->commercial);

        $this->patchJson("/api/v1/commercials/clients/{$client->id}/phone", [
            'phone' => '514 353 5820',
        ])->assertStatus(403);

        $this->assertNull($client->fresh()->phone);
    }

    // -------------------------------------------------------------- Enrichissement

    public function test_import_does_not_erase_a_manually_entered_phone(): void
    {
        $client = $this->makeClient([
            'phone' => '418-555-0001',
            'licence_number' => 'L-1000',
        ]);

        // Payload n8n sans numéro (ou « Téléphone » vide) : le numéro saisi
        // à la main est conservé — l'enrichissement ne fait que compléter.
        ClientImportService::upsertFromScraperPayload(['Licence' => 'L-1000', 'Téléphone' => '']);
        $this->assertSame('418-555-0001', $client->fresh()->phone);

        ClientImportService::upsertFromScraperPayload(['Licence' => 'L-1000']);
        $this->assertSame('418-555-0001', $client->fresh()->phone);

        // Un numéro fourni par l'import remplace bien l'ancien.
        ClientImportService::upsertFromScraperPayload(['Licence' => 'L-1000', 'Téléphone' => '555']);
        $this->assertSame('555', $client->fresh()->phone);
    }
}
