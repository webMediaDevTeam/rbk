<?php

namespace Tests\Feature;

use App\Models\GpsZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD des zones GPS (page admin « GPS ») + bascule de dérogation
 * « Connexion hors zones » (`PATCH /users/{id}/gps-bypass`), accordée pour
 * **24 h** seulement (`gps_bypass_expires_at`, cron `gps:expire-bypass`).
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

    // --------------------------- dérogation **limitée à 24 h** (auto-retirée)

    /**
     * Clic sur le switch « Hors zones » = **24 h** : l'échéance
     * (`gps_bypass_expires_at`) est posée à `now + 24 h`, la dérogation reste
     * effective jusqu'à là, puis le serveur la refuse et l'API rend le switch
     * éteint (état *effectif*, jamais le drapeau brut).
     */
    public function test_gps_bypass_is_granted_for_24_hours_only(): void
    {
        Sanctum::actingAs($this->admin());
        $commercial = User::factory()->create(['role' => 'COMERCIAL']);

        $this->patchJson("/api/v1/users/{$commercial->id}/gps-bypass", ['gps_bypass' => true])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.gps_bypass', true);

        $granted = $commercial->fresh();
        $this->assertNotNull($granted->gps_bypass_expires_at);
        $this->assertTrue(
            $granted->gps_bypass_expires_at->between(now(), now()->addMinutes(User::GPS_BYPASS_TTL_HOURS * 60 + 1)),
            'L\'échéance posée vaut 24 h.'
        );
        $this->assertTrue($granted->isGpsBypassActive());

        // 23 h 59 : encore accordée.
        $this->travel(23 * 60 + 59)->minutes();
        $this->assertTrue($commercial->fresh()->isGpsBypassActive());

        // Échéance dépassée : refusée, et la liste Employés montre un switch off.
        $this->travel(2)->minutes();
        $this->assertFalse($commercial->fresh()->isGpsBypassActive());

        $this->getJson('/api/v1/users?role=COMERCIAL')
            ->assertOk()
            ->assertJsonPath('data.utilisateurs.0.gps_bypass', false);

        $this->travelBack();
    }

    /**
     * Le drapeau reste `true` tant que le cron n'est pas passé : cliquer de
     * nouveau sur un switch affiché « éteint » doit **reposer 24 h** (sinon le
     * clic ne guérirait jamais l'échéance passée).
     */
    public function test_reactivating_an_expired_bypass_grants_a_fresh_24_hours(): void
    {
        Sanctum::actingAs($this->admin());
        $commercial = User::factory()->create(['role' => 'COMERCIAL']);

        $this->patchJson("/api/v1/users/{$commercial->id}/gps-bypass", ['gps_bypass' => true])->assertOk();

        $this->travel(25)->hours();
        $expired = $commercial->fresh();
        $this->assertTrue($expired->gps_bypass, 'Drapeau encore posé avant passage du cron.');
        $this->assertFalse($expired->isGpsBypassActive());

        $this->patchJson("/api/v1/users/{$commercial->id}/gps-bypass", ['gps_bypass' => true])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.gps_bypass', true);

        $renewed = $commercial->fresh();
        $this->assertTrue($renewed->isGpsBypassActive());
        $this->assertTrue(
            $renewed->gps_bypass_expires_at->greaterThan(now()->addHours(User::GPS_BYPASS_TTL_HOURS - 1)),
            'Une nouvelle échéance de 24 h est repartie du clic.'
        );

        $this->travelBack();
    }

    /**
     * `gps:expire-bypass` (cron) range la base : drapeau à `false`, échéance à
     * `null`. Les dérogations **sans échéance** (accordées avant la limitation)
     * ne sont pas touchées.
     */
    public function test_command_clears_expired_bypasses_only(): void
    {
        $commercial = User::factory()->create(['role' => 'COMERCIAL']);
        $commercial->setGpsBypass(true);
        $legacy = User::factory()->create(['role' => 'COMERCIAL', 'gps_bypass' => true]);

        $this->travel(25)->hours();
        $this->artisan('gps:expire-bypass')->assertExitCode(0);

        $cleared = $commercial->fresh();
        $this->assertFalse($cleared->gps_bypass);
        $this->assertNull($cleared->gps_bypass_expires_at);
        $this->assertFalse($cleared->isGpsBypassActive());

        $this->assertTrue($legacy->fresh()->gps_bypass, 'Sans échéance connue : non touchée.');

        $this->travelBack();
    }
}
