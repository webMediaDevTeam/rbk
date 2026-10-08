<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * « Privilège de libération » — colonne `users.has_permission`
 * (migration 2026_10_07_140018, docs/RULES.md §7.1).
 *
 *  - l'admin crée / édite un COMERCIAL avec le switch « Privilège de
 *    libération » (`POST/PUT users` → `sometimes|boolean`) ;
 *  - un COMERCIAL **sans** drapeau reçoit 403 sur les deux actions
 *    protégées : mise en liste noire (`clients/{id}/blacklist`) et
 *    libération de la liste (`reservations/release-pending`) ;
 *  - avec le drapeau →200, les actions s'exécutent ;
 *  - ADMIN / SUPER_ADMIN passent toujours (branche « Admin OU
 *    has_permission », middleware `CheckPermission`).
 */
class ReleasePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $enterpriseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $enterprise = Enterprise::forceCreate([
            'name' => 'Release Permission Enterprise',
        ]);
        $this->enterpriseId = $enterprise->id;
    }

    private function makeCommercial(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => 'AVAILABLE',
            'phone' => '514-555-0100',
        ], $attrs));
    }

    /** Réservation « en attente » du commercial (cible de libération). */
    private function makePendingReservation(Client $client, User $commercial): Reservation
    {
        return Reservation::create([
            'client_id' => $client->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_PENDING,
        ]);
    }

    /** Payload de création minimal (mot de passe → aucun e-mail de vérif). */
    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'privililege@demo.test',
            'mot_de_passe' => 'secret123',
            'mot_de_passe_confirmation' => 'secret123',
            'role' => 'COMERCIAL',
            'first_name' => 'Priva',
            'last_name' => 'Lège',
            'enterprise_id' => $this->enterpriseId,
        ], $overrides);
    }

    // ------------------------------------------- validation / CRUD admin

    public function test_admin_can_create_a_commercial_with_the_release_privilege(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->createPayload(['has_permission' => true]))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.utilisateur.has_permission', true);

        $this->assertTrue(
            User::where('email', 'privililege@demo.test')->first()->has_permission
        );
    }

    public function test_commercial_created_without_the_privilege_starts_denied(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->createPayload())
            ->assertCreated()
            ->assertJsonPath('data.utilisateur.has_permission', false);

        $this->assertFalse(
            User::where('email', 'privililege@demo.test')->first()->has_permission
        );
    }

    public function test_admin_can_toggle_the_privilege_when_updating(): void
    {
        $commercial = $this->makeCommercial();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/users/{$commercial->id}", ['has_permission' => true])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.has_permission', true);
        $this->assertTrue($commercial->fresh()->has_permission);

        $this->putJson("/api/v1/users/{$commercial->id}", ['has_permission' => false])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.has_permission', false);
        $this->assertFalse($commercial->fresh()->has_permission);
    }

    public function test_non_boolean_privilege_value_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->createPayload(['has_permission' => 'oui']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['has_permission']);
    }

    // --------------------------------------------------- portes d'accès

    public function test_commercial_without_the_privilege_cannot_blacklist_a_client(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/blacklist", ['note' => 'Test privilège'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Accès refusé : privilège de libération requis.');

        // Aucune mutation : la fiche reste intacte.
        $fresh = $client->fresh();
        $this->assertSame('AVAILABLE', $fresh->status);
        $this->assertFalse($fresh->is_blacklisted);
    }

    public function test_commercial_without_the_privilege_cannot_release_his_list(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makePendingReservation($client, $commercial);
        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/reservations/release-pending')->assertForbidden();

        // La réservation « en attente » est intacte.
        $this->assertSame('RESERVED', $client->fresh()->status);
        $this->assertNotNull(Reservation::first());
    }

    public function test_commercial_with_the_privilege_can_blacklist_a_client(): void
    {
        $commercial = $this->makeCommercial(['has_permission' => true]);
        $client = $this->makeClient();
        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/blacklist", ['note' => 'OK'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $client->fresh();
        $this->assertTrue($fresh->is_blacklisted);
        $this->assertSame('BLACKLISTED', $fresh->status);
    }

    public function test_commercial_with_the_privilege_can_release_his_list(): void
    {
        $commercial = $this->makeCommercial(['has_permission' => true]);
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makePendingReservation($client, $commercial);
        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/reservations/release-pending')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('released', 1);

        $this->assertSame('AVAILABLE', $client->fresh()->status);
        $this->assertDatabaseCount('reservations', 0);
    }

    // ------------------------------------- règle « Admin OU has_permission »

    /**
     * Les routes protégées ne laissent passer que des COMERCIAL
     * (`CheckRole` tourne avant) : la branche Admin du middleware est
     * donc vérifiée directement, comme la règle complète « Admin OU
     * drapeau ».
     */
    public function test_check_permission_allows_admins_and_flagged_commercials_only(): void
    {
        $call = function (User $user): Response {
            $request = Request::create('/api/v1/clients/x/blacklist', 'POST');
            $request->setUserResolver(fn () => $user);

            return (new CheckPermission)->handle(
                $request,
                fn () => response()->json(['ok' => true], 200)
            );
        };

        // Admin : toujours autorisé (la hiérarchie passe au-dessus).
        $this->assertSame(200, $call($this->admin)->getStatusCode());

        // Commercial avec drapeau : 200.
        $this->assertSame(200, $call($this->makeCommercial(['has_permission' => true]))->getStatusCode());

        // Commercial sans drapeau : 403.
        $denied = $call($this->makeCommercial());
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(
            'Accès refusé : privilège de libération requis.',
            json_decode($denied->getContent(), true)['message']
        );
    }
}
