<?php

namespace Tests\Feature;

use App\Models\GpsZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD des zones GPS (page admin « GPS ») + bascule de dérogation
 * « Connexion hors zones » (`PATCH /users/{id}/gps-bypass`).
 *
 * Périmètre : routes ADMIN / SUPER_ADMIN uniquement ; le bypass ne s'applique
 * qu'aux rôles contraints (COMERCIAL / OPERATOR).
 */
class GpsZoneApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'ADMIN']);
    }

    public function test_admin_can_create_a_zone(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/gps/zones', [
            'name' => 'Bureau Montréal',
            'latitude' => 45.5017,
            'longitude' => -73.5673,
            'radius_m' => 750,
        ])
            ->assertCreated()
            ->assertJsonPath('zone.name', 'Bureau Montréal')
            ->assertJsonPath('zone.radius_m', 750)
            ->assertJsonPath('zone.is_active', true);

        $this->assertDatabaseHas('gps_zones', ['name' => 'Bureau Montréal', 'radius_m' => 750]);
    }

    public function test_commercial_cannot_manage_zones(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL']));

        $this->getJson('/api/v1/gps/zones')->assertForbidden();
        $this->postJson('/api/v1/gps/zones', [
            'name' => 'Nope',
            'latitude' => 1,
            'longitude' => 1,
        ])->assertForbidden();
    }

    public function test_zone_validation_bounds_are_enforced(): void
    {
        Sanctum::actingAs($this->admin());

        // Rayon hors bornes [50, 1000] + latitude invalide.
        $this->postJson('/api/v1/gps/zones', [
            'name' => 'Gratte-ciel',
            'latitude' => 95,
            'longitude' => -73.5673,
            'radius_m' => 1500,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'radius_m']);

        // Nom obligatoire.
        $this->postJson('/api/v1/gps/zones', [
            'latitude' => 45.5,
            'longitude' => -73.5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    public function test_zone_can_be_updated_and_deleted(): void
    {
        Sanctum::actingAs($this->admin());

        $zone = GpsZone::create([
            'name' => 'Ancien nom',
            'latitude' => 36.8065,
            'longitude' => 10.1815,
            'radius_m' => 500,
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/gps/zones/{$zone->id}", [
            'name' => 'Tunis Centre',
            'radius_m' => 850,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('zone.name', 'Tunis Centre')
            ->assertJsonPath('zone.radius_m', 850)
            ->assertJsonPath('zone.is_active', false);

        $this->deleteJson("/api/v1/gps/zones/{$zone->id}")->assertOk();
        $this->assertDatabaseMissing('gps_zones', ['id' => $zone->id]);
    }

    public function test_index_lists_zones_newest_first(): void
    {
        Sanctum::actingAs($this->admin());

        $old = GpsZone::create(['name' => 'Vieille', 'latitude' => 1, 'longitude' => 1, 'radius_m' => 100]);
        $new = GpsZone::create(['name' => 'Nouvelle', 'latitude' => 2, 'longitude' => 2, 'radius_m' => 100]);

        $response = $this->getJson('/api/v1/gps/zones')->assertOk();
        $names = array_column($response->json('zones'), 'name');

        $this->assertSame('Nouvelle', $names[0]);
        $this->assertContains('Vieille', $names);
    }

    // ------------------------------------------------- dérogation « hors zones »

    public function test_manager_can_toggle_gps_bypass_for_a_constrained_role(): void
    {
        Sanctum::actingAs($this->admin());
        $commercial = User::factory()->create(['role' => 'COMERCIAL']);

        $this->patchJson("/api/v1/users/{$commercial->id}/gps-bypass", ['gps_bypass' => true])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.gps_bypass', true);

        $this->assertTrue($commercial->fresh()->gps_bypass);

        $this->patchJson("/api/v1/users/{$commercial->id}/gps-bypass", ['gps_bypass' => false])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.gps_bypass', false);

        $this->assertFalse($commercial->fresh()->gps_bypass);
    }

    public function test_operator_can_receive_the_bypass_too(): void
    {
        Sanctum::actingAs($this->admin());
        $operator = User::factory()->create(['role' => 'OPERATOR']);

        $this->patchJson("/api/v1/users/{$operator->id}/gps-bypass", ['gps_bypass' => true])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.gps_bypass', true);
    }

    public function test_bypass_cannot_be_set_on_a_non_enforced_role(): void
    {
        // ACTEUR = SUPER_ADMIN : un ADMIN ne peut pas toucher à un pair
        // (`canAct()` hiérarchie stricte → 403) — ici on teste la logique
        // métier « rôle non contraint », donc la hiérarchie doit passer.
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN']));
        $otherAdmin = User::factory()->create(['role' => 'ADMIN']);

        // Un ADMIN n'est jamais bloqué : régler son bypass n'a pas de sens.
        $this->patchJson("/api/v1/users/{$otherAdmin->id}/gps-bypass", ['gps_bypass' => true])
            ->assertUnprocessable();
    }

    public function test_commercial_cannot_toggle_gps_bypass(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL']));
        $target = User::factory()->create(['role' => 'COMERCIAL']);

        $this->patchJson("/api/v1/users/{$target->id}/gps-bypass", ['gps_bypass' => true])
            ->assertForbidden();
    }

    public function test_users_index_exposes_gps_bypass_as_boolean(): void
    {
        Sanctum::actingAs($this->admin());
        User::factory()->create(['role' => 'COMERCIAL', 'gps_bypass' => true]);

        $this->getJson('/api/v1/users?role=COMERCIAL')
            ->assertOk()
            ->assertJsonPath('data.utilisateurs.0.gps_bypass', true);
    }
}
