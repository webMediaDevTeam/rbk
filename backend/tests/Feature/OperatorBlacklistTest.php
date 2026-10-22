<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Accès « liste noire » de l'`OPERATOR` dans la Grande liste : il peut
 * **bloquer** une fiche de SON périmètre (`AVAILABLE`, non blacklistée, sans
 * téléphone — `applyOperatorScope`), mais ne peut ni toucher une fiche hors
 * périmètre (404) ni **débloquer** (`liste-noire/{id}/debloquer` reste
 * réservé aux gestionnaires). Les gestionnaires gardent la bascule complète.
 */
class OperatorBlacklistTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'OPERATOR', 'status' => 'ACTIVE']);
        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
    }

    /**
     * Fiche « Disponible sans téléphone » par défaut : dans le périmètre forcé
     * de l'opérateur (`AVAILABLE`, non blacklistée, `phone` NULL).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => null,
            'is_blacklisted' => false,
        ], $attrs));
    }

    public function test_operator_can_blacklist_a_client_in_his_scope(): void
    {
        $client = $this->makeClient(['name' => 'Sans numéro 1']);

        Sanctum::actingAs($this->operator);

        $this->postJson("/api/v1/commercials/clients/{$client->id}/blacklist", ['note' => 'Fiche erronée'])
            ->assertOk();

        $client->refresh();
        $this->assertTrue((bool) $client->is_blacklisted);
        $this->assertSame(Client::STATUS_BLACKLISTED, $client->status);
        // Note journalisée, émetteur = l'opérateur.
        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'type' => Note::TYPE_BLACKLISTED,
            'sender_id' => $this->operator->id,
        ]);
    }

    public function test_operator_cannot_blacklist_a_client_outside_his_scope(): void
    {
        // Téléphone renseigné → hors « sans téléphone ».
        $withPhone = $this->makeClient(['phone' => '514-555-0100']);
        // Déjà blacklisté → hors périmètre.
        $blocked = $this->makeClient(['is_blacklisted' => true, 'status' => Client::STATUS_BLACKLISTED]);
        // Réservé → hors statut `AVAILABLE`.
        $reserved = $this->makeClient(['status' => Client::STATUS_RESERVED]);

        Sanctum::actingAs($this->operator);

        foreach ([$withPhone, $blocked, $reserved] as $client) {
            $this->postJson("/api/v1/commercials/clients/{$client->id}/blacklist", [])
                ->assertNotFound();
        }
    }

    public function test_operator_cannot_unblock_a_client(): void
    {
        $client = $this->makeClient();

        // Blocage par un gestionnaire pour mettre une fiche hors périmètre.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/commercials/clients/{$client->id}/blacklist", [])->assertOk();

        Sanctum::actingAs($this->operator);
        // Débloquage réservé aux gestionnaires : 403 pour l'opérateur.
        $this->postJson("/api/v1/liste-noire/{$client->id}/debloquer")->assertForbidden();
        $this->assertTrue((bool) $client->refresh()->is_blacklisted);
    }

    public function test_admin_can_still_blacklist_any_client_including_one_with_phone(): void
    {
        $client = $this->makeClient(['phone' => '514-555-0199']);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/commercials/clients/{$client->id}/blacklist", [])->assertOk();
        $this->assertTrue((bool) $client->refresh()->is_blacklisted);
    }
}
