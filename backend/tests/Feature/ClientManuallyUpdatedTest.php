<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fiche « modifiée à la main » — `clients.is_manually_updated`
 * (migration 2026_10_07_132510, docs/RULES.md §12).
 *
 * Fil conducteur (flow de vérification du TODO) :
 *
 *   1. import scraper / n8n → client neuf, drapeau `false` ;
 *   2. édition humaine via l'interface (`PATCH commercials/clients/{id}/phone`)
 *      → drapeau `true` ;
 *   3. nouvel upsert → `skipped_manual`, la moindre donnée de la fiche
 *      reste intacte.
 *
 *   4. Un lot mélangeant fiche manuelle + client neuf : le skip n'arrête
 *      pas le reste du batch ;
 *   5. Les actions de **workflow** (liste noire…) n'écrivent que l'état
 *      applicatif, jamais géré par l'import → elles ne posent **pas** le
 *      drapeau et la synchro continue.
 */
class ClientManuallyUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private const UPSERT = '/api/v1/clients/bulk-upsert';

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // Webhooks publics protégés : en-tête X-Api-Key (VerifyExternalSystemKey).
        $this->withHeaders(['X-Api-Key' => (string) config('services.external_system.key')]);

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        // Privilège de libération : ce test vérifie le cycle d'édition de
        // fiche (is_manually_updated), pas le portail — le commercial doit
        // pouvoir atteindre `clients/{id}/blacklist` (CheckPermission).
        $this->commercial = User::factory()->create([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
            'has_permission' => true,
        ]);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function upsert(array $items)
    {
        return $this->postJson(self::UPSERT, ['clients' => $items]);
    }

    /** @return array<string, mixed> */
    private function scraperItem(string $licence, array $overrides = []): array
    {
        return array_merge([
            'Licence' => $licence,
            "Nom de l'intervenant / Entreprise" => 'ACME Construction',
            'Municipalité' => 'Québec',
            'Téléphone' => '418-555-0001',
        ], $overrides);
    }

    private function importOne(string $licence = 'L-1001'): Client
    {
        $this->upsert([$this->scraperItem($licence)])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        return Client::where('licence_number', $licence)->firstOrFail();
    }

    private function manualPhoneEdit(Client $client, string $phone): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/commercials/clients/{$client->id}/phone", ['phone' => $phone])
            ->assertOk()
            ->assertJsonPath('data.client.phone', Client::cleanPhone($phone));
    }

    // ------------------------------------------------------- 1. import neuf

    public function test_imported_client_is_not_flagged_as_manually_updated(): void
    {
        $client = $this->importOne();

        // Cast boolean + défaut de colonne à false : l'import n'est pas une
        // saisie manuelle.
        $this->assertFalse($client->is_manually_updated);
    }

    // ----------------------------------------------- 2. édition manuelle

    public function test_manual_phone_update_sets_the_flag(): void
    {
        $client = $this->importOne();
        $this->assertFalse($client->is_manually_updated);

        $this->manualPhoneEdit($client, '418 555 9999');

        $fresh = $client->fresh();
        $this->assertSame('418-555-9999', $fresh->phone);
        $this->assertTrue($fresh->is_manually_updated);
    }

    // ---------------------------------- 3. resync qui refuse de réécrire

    public function test_upsert_leaves_a_manually_updated_client_untouched(): void
    {
        $client = $this->importOne();
        $this->manualPhoneEdit($client, '418 555 9999');

        $updatedAt = $client->fresh()->updated_at;

        // Resynchronisation scraper avec des données **modifiées** :
        // le téléphone saisi à la main et la municipalité d'origine doivent
        // survivre, et aucun `updated_at` ne doit bouger.
        $this->upsert([$this->scraperItem('L-1001', [
            'Téléphone' => '418-555-7777',
            'Municipalité' => 'Lévis',
            "Nom de l'intervenant / Entreprise" => 'Bâtiment Refait Inc.',
        ])])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.skipped_manual', 1)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.unchanged', 0)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.processed', 1);

        $fresh = $client->fresh();
        $this->assertSame('418-555-9999', $fresh->phone);
        $this->assertSame('Québec', $fresh->municipality);
        $this->assertSame('ACME Construction', $fresh->name);
        $this->assertTrue($fresh->is_manually_updated);
        $this->assertTrue($fresh->updated_at->equalTo($updatedAt));
    }

    // --------------------------------------------------- 4. lot mixte

    public function test_bulk_upsert_counts_manual_skips_without_stopping_the_batch(): void
    {
        $manual = $this->importOne('L-1001');
        $this->manualPhoneEdit($manual, '418 555 9999');

        // Lot : la fiche protégée **plus** un client inconnu — le reste du
        // batch doit être traité normalement.
        $this->upsert([
            $this->scraperItem('L-1001', ['Municipalité' => 'Lévis']),
            $this->scraperItem('L-9999', ["Nom de l'intervenant / Entreprise" => 'Nouveau Client Inc.']),
        ])
            ->assertOk()
            ->assertJsonPath('data.received', 2)
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.skipped_manual', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.failed', 0);

        $this->assertSame('Québec', $manual->fresh()->municipality);
        $this->assertTrue($manual->fresh()->is_manually_updated);

        $created = Client::where('licence_number', 'L-9999')->firstOrFail();
        $this->assertSame('Nouveau Client Inc.', $created->name);
        $this->assertFalse($created->is_manually_updated);
    }

    // ------------------------------- 5. workflow ≠ édition de fiche

    public function test_workflow_actions_like_blacklisting_do_not_flag_the_client(): void
    {
        $client = $this->importOne('L-1001');

        // Liste noire posée par un humain **depuis l'interface** : elle
        // n'écrit que l'état applicatif (`status`, `is_blacklisted`,
        // `returned_at`), que l'import ne réécrit jamais — la fiche reste
        // donc synchronisable.
        Sanctum::actingAs($this->commercial);
        $this->postJson("/api/v1/clients/{$client->id}/blacklist", ['note' => 'Test scope'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $client->fresh();
        $this->assertTrue($fresh->is_blacklisted);
        $this->assertFalse($fresh->is_manually_updated);

        // La resync passe donc toujours : la donnée d'import est réécrite.
        $this->upsert([$this->scraperItem('L-1001', ['Municipalité' => 'Lévis'])])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped_manual', 0);

        $this->assertSame('Lévis', $client->fresh()->municipality);
    }
}
