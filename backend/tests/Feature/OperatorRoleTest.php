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
 * Rôle `OPERATOR` — créé par ADMIN / SUPER_ADMIN, accès **unique** à la
 * Grande liste restreinte aux « Disponibles sans téléphone »
 * (`status = AVAILABLE`, non blacklisté, `phone` NULL ou vide), avec
 * édition complète de fiche. Le périmètre est forcé par le contrôleur :
 * aucun paramètre envoyé ne peut l'élargir.
 */
class OperatorRoleTest extends TestCase
{
    use RefreshDatabase;

    private function makeOperator(): User
    {
        return User::factory()->create(['role' => 'OPERATOR', 'status' => 'ACTIVE']);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Prospect',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => null,
        ], $attrs));
    }

    // ── Grande liste : périmètre forcé ────────────────────────────────────

    public function test_operator_only_sees_available_clients_without_phone(): void
    {
        $inScope = $this->makeClient(['name' => 'Dans le périmètre']);
        $withPhone = $this->makeClient(['name' => 'Avec numéro', 'phone' => '514-555-0100']);
        $blacklisted = $this->makeClient(['name' => 'Blacklisté', 'is_blacklisted' => true]);
        $reserved = $this->makeClient(['name' => 'Réservé', 'status' => Client::STATUS_RESERVED]);

        Sanctum::actingAs($this->makeOperator());

        $response = $this->getJson('/api/v1/commercials/clients')->assertOk();

        $names = collect($response->json('data.clients'))->pluck('name')->all();
        $this->assertSame(['Dans le périmètre'], $names);
        $this->assertNotContains($withPhone->name, $names);
        $this->assertNotContains($blacklisted->name, $names);
        $this->assertNotContains($reserved->name, $names);
    }

    public function test_operator_list_scope_includes_empty_string_phone(): void
    {
        // Les deux formes « sans numéro » existent en base (NULL et '').
        $this->makeClient(['name' => 'NULL', 'phone' => null]);
        $this->makeClient(['name' => 'Vide', 'phone' => '']);

        Sanctum::actingAs($this->makeOperator());

        $names = collect($this->getJson('/api/v1/commercials/clients')
            ->assertOk()
            ->json('data.clients'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['NULL', 'Vide'], $names);
    }

    public function test_operator_query_filters_are_ignored(): void
    {
        $inScope = $this->makeClient(['name' => 'Sans numéro', 'source' => 'Angalis']);
        $outScope = $this->makeClient(['name' => 'Avec numéro', 'phone' => '438-555-0101', 'source' => 'Angalis']);

        Sanctum::actingAs($this->makeOperator());

        // Le fournisseur d'accès force le périmètre : badges, onglets
        // « source » et filtres d'interaction n'élargissent rien.
        $response = $this->getJson('/api/v1/commercials/clients?status=AVAILABLE&source=Angalis&enterprise_id=nimporte&reservation_status=NOI')
            ->assertOk();

        $names = collect($response->json('data.clients'))->pluck('name')->all();
        $this->assertSame(['Sans numéro'], $names);
        $this->assertNotContains($outScope->name, $names);
        $this->assertNotEmpty($inScope->id);
    }

    // ── Édition de fiche ──────────────────────────────────────────────────

    public function test_operator_can_update_client_within_scope(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($this->makeOperator());

        $this->putJson("/api/v1/commercials/clients/{$client->id}", [
            'name' => 'Nom complété',
            'phone' => '5143535820',
        ])->assertOk()->assertJsonPath('data.client.phone', '514-353-5820');

        $client->refresh();
        $this->assertSame('Nom complété', $client->name);
        $this->assertTrue($client->is_manually_updated);
    }

    public function test_operator_cannot_read_or_update_client_outside_scope(): void
    {
        $outOfScope = $this->makeClient(['phone' => '514-555-0100']);
        Sanctum::actingAs($this->makeOperator());

        $this->getJson("/api/v1/commercials/clients/{$outOfScope->id}")->assertNotFound();
        $this->putJson("/api/v1/commercials/clients/{$outOfScope->id}", ['name' => 'Piraté'])->assertNotFound();
        $this->patchJson("/api/v1/commercials/clients/{$outOfScope->id}/phone", ['phone' => '438-555-0101'])->assertNotFound();

        $this->assertSame('Prospect', $outOfScope->refresh()->name);
    }

    public function test_admin_list_is_not_restricted_by_the_operator_scope(): void
    {
        $this->makeClient(['name' => 'Avec numéro', 'phone' => '514-555-0100']);
        Sanctum::actingAs($this->makeAdmin());

        $names = collect($this->getJson('/api/v1/commercials/clients')
            ->assertOk()
            ->json('data.clients'))->pluck('name')->all();

        $this->assertContains('Avec numéro', $names);
    }

    // ── Routes réservées aux gestionnaires ────────────────────────────────

    public function test_operator_cannot_reach_manager_only_routes(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($this->makeOperator());

        $this->postJson("/api/v1/commercials/clients/{$client->id}/blacklist")->assertForbidden();
        $this->getJson('/api/v1/commercials')->assertForbidden();
        $this->getJson('/api/v1/liste-noire')->assertForbidden();
    }

    // ── Création / gestion du rôle ────────────────────────────────────────

    public function test_admin_can_create_an_operator_without_employee_record(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $response = $this->postJson('/api/v1/users', [
            'email' => 'operateur@exemple.com',
            'role' => 'OPERATOR',
            'first_name' => 'Opé',
            'last_name' => 'Rateur',
            'mot_de_passe' => 'motdepasse8',
            'mot_de_passe_confirmation' => 'motdepasse8',
        ])->assertCreated()->assertJsonPath('data.utilisateur.role', 'OPERATOR');

        $user = User::find($response->json('data.utilisateur.id'));
        $this->assertNotNull($user);
        // Aucun enregistrement `employees` pour un opérateur.
        $this->assertSame(0, Employee::where('user_id', $user->id)->count());
        // Le profil exposé est la simple identité, sans entreprise imbriquée.
        $this->assertSame('Opé', $response->json('data.utilisateur.profil.prenom'));
        $this->assertNull($user->employee);
    }

    public function test_operator_creation_requires_a_password(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/v1/users', [
            'email' => 'operateur@exemple.com',
            'role' => 'OPERATOR',
            'first_name' => 'Opé',
            'last_name' => 'Rateur',
        ])->assertUnprocessable()->assertJsonValidationErrors(['mot_de_passe']);
    }

    public function test_commercial_cannot_create_an_operator(): void
    {
        $commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/users', [
            'email' => 'operateur@exemple.com',
            'role' => 'OPERATOR',
            'first_name' => 'Opé',
            'last_name' => 'Rateur',
            'mot_de_passe' => 'motdepasse8',
            'mot_de_passe_confirmation' => 'motdepasse8',
        ])->assertForbidden();
    }

    public function test_admin_list_includes_operators(): void
    {
        $enterprise = Enterprise::create(['name' => 'Entreprise A']);
        $commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE', 'first_name' => 'Comm', 'last_name' => 'ercial']);
        Employee::create([
            'user_id' => $commercial->id,
            'enterprise_id' => $enterprise->id,
            'first_name' => 'Comm',
            'last_name' => 'ercial',
        ]);
        $this->makeOperator();

        Sanctum::actingAs($this->makeAdmin());

        $roles = collect($this->getJson('/api/v1/users?role=COMERCIAL,OPERATOR&per_page=100')
            ->assertOk()
            ->json('data.utilisateurs'))->pluck('role')->sort()->values()->all();

        $this->assertSame(['COMERCIAL', 'OPERATOR'], $roles);
    }

    // ── Mot de passe : « demander à l'admin » ─────────────────────────────

    public function test_operator_cannot_change_own_password(): void
    {
        Sanctum::actingAs($this->makeOperator());

        $this->putJson('/api/v1/auth/profile/password', [
            'current_password' => 'password',
            'password' => 'nouveau8char',
            'password_confirmation' => 'nouveau8char',
        ])->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_ADMIN_ONLY');
    }
}
