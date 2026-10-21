<?php

namespace Tests\Feature;

use App\Models\GpsZone;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coupe-circuit **global** du contrôle GPS à la connexion (interrupteur de la
 * page admin « GPS ») : `GET|PUT /gps/settings` côté gestionnaires, et
 * `GET /auth/geofence` côté public pour que la page de connexion sache si elle
 * doit interroger le navigateur.
 *
 * Règle porteuse : la valeur vit en base (`settings`) et **n'écrase la
 * configuration que si la ligne existe**. Ainsi `phpunit.xml` (qui force
 * `GEOFENCE_ENABLED=false`) et les tests qui réactivent la config à la volée
 * restent valables, tout en permettant à un ADMIN d'allumer ou d'éteindre sans
 * redéploiement.
 */
class GpsSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'ADMIN']);
    }

    public function test_public_geofence_status_is_disabled_by_default(): void
    {
        // Aucune ligne en base, config éteinte (`phpunit.xml`) → éteint. La
        // route est publique : la page de connexion l'appelle avant d'avoir
        // le moindre jeton.
        $this->getJson('/api/v1/auth/geofence')
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }

    public function test_public_geofence_status_follows_config_then_database(): void
    {
        // Repli : la configuration commande tant qu'aucune préférence n'existe.
        config(['geofence.enabled' => true]);
        $this->getJson('/api/v1/auth/geofence')->assertOk()->assertJsonPath('enabled', true);

        // La base prend le dessus, y compris contre une config optimiste.
        Setting::setBool(Setting::GEOFENCE_ENABLED, false);
        $this->getJson('/api/v1/auth/geofence')->assertOk()->assertJsonPath('enabled', false);

        Setting::setBool(Setting::GEOFENCE_ENABLED, true);
        $this->getJson('/api/v1/auth/geofence')->assertOk()->assertJsonPath('enabled', true);
    }

    public function test_admin_reads_settings_with_active_zone_count(): void
    {
        Sanctum::actingAs($this->admin());

        GpsZone::create(['name' => 'Active', 'latitude' => 45.5, 'longitude' => -73.5, 'radius_m' => 500, 'is_active' => true]);
        GpsZone::create(['name' => 'Endormie', 'latitude' => 36.8, 'longitude' => 10.18, 'radius_m' => 500, 'is_active' => false]);

        $this->getJson('/api/v1/gps/settings')
            ->assertOk()
            ->assertJsonPath('geofence_enabled', false)
            ->assertJsonPath('active_zones', 1)
            ->assertJsonPath('enforced_roles.0', 'COMERCIAL');
    }

    public function test_admin_can_toggle_the_switch_and_it_persists(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/v1/gps/settings', ['geofence_enabled' => true])
            ->assertOk()
            ->assertJsonPath('geofence_enabled', true)
            ->assertJsonPath('message', 'Contrôle GPS à la connexion activé.');

        $this->assertSame('1', Setting::value(Setting::GEOFENCE_ENABLED));

        $this->putJson('/api/v1/gps/settings', ['geofence_enabled' => false])
            ->assertOk()
            ->assertJsonPath('geofence_enabled', false)
            ->assertJsonPath('message', 'Contrôle GPS à la connexion désactivé.');

        // Une seule ligne par clé : la bascule met à jour, ne duplique pas.
        $this->assertSame(1, Setting::query()->where('key', Setting::GEOFENCE_ENABLED)->count());
    }

    public function test_switch_value_is_required_to_be_boolean(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/v1/gps/settings', [])->assertUnprocessable()->assertJsonValidationErrors('geofence_enabled');
        $this->putJson('/api/v1/gps/settings', ['geofence_enabled' => 'pas-un-bootéen'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('geofence_enabled');
    }

    public function test_constrained_roles_cannot_read_or_write_the_switch(): void
    {
        foreach (['COMERCIAL', 'OPERATOR'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $this->getJson('/api/v1/gps/settings')->assertForbidden();
            $this->putJson('/api/v1/gps/settings', ['geofence_enabled' => true])->assertForbidden();
        }

        // Invité : la seule chose qu'il puisse lire est l'état public.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/gps/settings')->assertUnauthorized();
        $this->getJson('/api/v1/auth/geofence')->assertOk();
    }

    /**
     * Le geste de l'ADMIN prime sur le déploiement : un `GEOFENCE_ENABLED=true`
     * laissé dans l'environnement ne peut pas rouvrir le contrôle une fois la
     * bascule éteinte en base — et inversement.
     */
    public function test_database_switch_overrides_config_at_login(): void
    {
        config(['geofence.enabled' => true, 'geofence.enforced_roles' => ['COMERCIAL', 'OPERATOR']]);

        GpsZone::create([
            'name' => 'Bureau Montréal',
            'latitude' => 45.5017,
            'longitude' => -73.5673,
            'radius_m' => 500,
            'is_active' => true,
        ]);

        $commercial = User::factory()->create([
            'email' => 'switch@example.com',
            'password_hash' => Hash::make('secret-password'),
            'role' => 'COMERCIAL',
        ]);

        // Éteint en base malgré la config allumée : connexion sans position.
        Setting::setBool(Setting::GEOFENCE_ENABLED, false);
        $this->postJson('/api/v1/auth/login', ['email' => $commercial->email, 'password' => 'secret-password'])
            ->assertOk();

        // Rouvert depuis la page « GPS », config inchangée : la position redevient obligatoire.
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/v1/gps/settings', ['geofence_enabled' => true])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $commercial->email, 'password' => 'secret-password'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LOCATION_REQUIRED');
    }

    public function test_switch_can_be_turned_on_without_any_config_change(): void
    {
        // Config éteinte (défaut de déploiement « désactivé par défaut ») :
        // allumer depuis l'interface doit suffire, sans redéploiement.
        config(['geofence.enabled' => false, 'geofence.enforced_roles' => ['COMERCIAL', 'OPERATOR']]);

        Sanctum::actingAs($this->admin());
        $this->putJson('/api/v1/gps/settings', ['geofence_enabled' => true])->assertOk();

        $this->getJson('/api/v1/auth/geofence')->assertOk()->assertJsonPath('enabled', true);
    }
}
