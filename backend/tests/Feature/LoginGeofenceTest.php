<?php

namespace Tests\Feature;

use App\Models\GpsZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Géorepérage de la connexion (GeofenceService + AuthController).
 *
 * `phpunit.xml` force `GEOFENCE_ENABLED=false` pour ne pas casser les tests
 * de login existants ; ici on réactive la config par test. Rôles contraints
 * (COMERCIAL / OPERATOR) sans dérogation doivent fournir une position précise
 * tombant dans une zone active, sinon 403. ADMIN / SUPER_ADMIN jamais bloqués.
 *
 * Référence géo : centre Montréal (45.5017, -73.5673) et Tunis
 * (36.8065, 10.1815) — zones au rayon 500 m.
 */
class LoginGeofenceTest extends TestCase
{
    use RefreshDatabase;

    private const MTL_LAT = 45.5017;
    private const MTL_LNG = -73.5673;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geofence.enabled' => true,
            'geofence.max_accuracy_m' => 100,
            'geofence.enforced_roles' => ['COMERCIAL', 'OPERATOR'],
        ]);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'geo.test@example.com',
            'password_hash' => Hash::make('secret-password'),
            'role' => 'COMERCIAL',
        ], $overrides));
    }

    private function zone(array $attributes = []): GpsZone
    {
        return GpsZone::create(array_merge([
            'name' => 'Bureau Montréal',
            'latitude' => self::MTL_LAT,
            'longitude' => self::MTL_LNG,
            'radius_m' => 500,
            'is_active' => true,
        ], $attributes));
    }

    /** Position « au bord » : ~450 m au nord du centre (dans un rayon 500 m). */
    private function insidePoint(): array
    {
        // 1 degré de latitude ≈ 111 195 m → 450 m ≈ 0.004047°.
        return ['lat' => self::MTL_LAT + 0.004047, 'lng' => self::MTL_LNG];
    }

    /** Position juste hors rayon : ~610 m au nord (dans un rayon 500 m → dehors). */
    private function outsidePoint(): array
    {
        return ['lat' => self::MTL_LAT + 0.0055, 'lng' => self::MTL_LNG];
    }

    private function loginPayload(User $user, array $geo = []): array
    {
        return array_merge([
            'email' => $user->email,
            'password' => 'secret-password',
        ], $geo);
    }

    public function test_commercial_inside_an_active_zone_can_log_in(): void
    {
        $this->zone();
        $user = $this->makeUser();
        $inside = $this->insidePoint();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, [
            'lat' => $inside['lat'],
            'lng' => $inside['lng'],
            'accuracy' => 40,
        ]))
            ->assertOk()
            ->assertJsonStructure(['utilisateur', 'profil', 'jeton']);
    }

    public function test_commercial_outside_every_zone_is_rejected(): void
    {
        $this->zone();
        $user = $this->makeUser();
        $outside = $this->outsidePoint();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, [
            'lat' => $outside['lat'],
            'lng' => $outside['lng'],
            'accuracy' => 40,
        ]))
            ->assertForbidden()
            ->assertJsonPath('code', 'OUTSIDE_GEOFENCE');
    }

    public function test_commercial_without_any_location_is_rejected(): void
    {
        $this->zone();
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJsonPath('code', 'LOCATION_REQUIRED');
    }

    public function test_commercial_with_poor_accuracy_is_rejected(): void
    {
        $this->zone();
        $user = $this->makeUser();
        $inside = $this->insidePoint();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, [
            'lat' => $inside['lat'],
            'lng' => $inside['lng'],
            'accuracy' => 500,
        ]))
            ->assertForbidden()
            ->assertJsonPath('code', 'LOW_ACCURACY');
    }

    public function test_bypassed_commercial_logs_in_from_anywhere(): void
    {
        $this->zone(); // même avec une zone loin, la dérogation passe avant.
        $user = $this->makeUser(['gps_bypass' => true]);

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, [
            'lat' => 36.8065, // Tunis, très loin de Montréal.
            'lng' => 10.1815,
            'accuracy' => 30,
        ]))->assertOk();
    }

    /**
     * Dérogation **limitée à 24 h** : posée par le switch (`setGpsBypass()`),
     * elle ouvre la connexion hors zone tant qu'elle court, puis le refus
     * reprend **immédiatement** à l'échéance — sans attendre le cron
     * `gps:expire-bypass` qui ne fait que ranger le drapeau.
     */
    public function test_expired_bypass_no_longer_allows_login_outside_a_zone(): void
    {
        $this->zone();
        $user = $this->makeUser();
        $user->setGpsBypass(true);

        $far = ['lat' => 36.8065, 'lng' => 10.1815, 'accuracy' => 30];

        // Dans les 24 h : la dérogation passe.
        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, $far))->assertOk();

        // 24 h + 1 : plus de dérogation, la position doit tomber dans une zone.
        $this->travel(User::GPS_BYPASS_TTL_HOURS + 1)->hours();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, $far))
            ->assertForbidden()
            ->assertJsonPath('code', 'OUTSIDE_GEOFENCE');

        $this->travelBack();
    }

    public function test_operator_is_enforced_too(): void
    {
        $this->zone();
        $user = $this->makeUser(['role' => 'OPERATOR']);

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user))
            ->assertForbidden()
            ->assertJsonPath('code', 'LOCATION_REQUIRED');
    }

    public function test_admin_and_super_admin_are_never_blocked(): void
    {
        // Aucune zone, aucune coordonnée : un gestionnaire se connecte quand même.
        foreach (['ADMIN', 'SUPER_ADMIN'] as $role) {
            $user = $this->makeUser(['role' => $role, 'email' => strtolower($role).'@example.com']);

            $this->postJson('/api/v1/auth/login', $this->loginPayload($user))
                ->assertOk()
                ->assertJsonPath('utilisateur.role', $role);
        }
    }

    public function test_inactive_zone_does_not_authorize_login(): void
    {
        $this->zone(['is_active' => false]);
        $user = $this->makeUser();
        $inside = $this->insidePoint();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($user, [
            'lat' => $inside['lat'],
            'lng' => $inside['lng'],
            'accuracy' => 40,
        ]))
            ->assertForbidden()
            ->assertJsonPath('code', 'OUTSIDE_GEOFENCE');
    }

    public function test_geofence_can_be_globally_disabled(): void
    {
        config(['geofence.enabled' => false]);

        $this->zone();
        $user = $this->makeUser();

        // Rôle contraint, aucune coordonnée, mais géorepérage coupé → OK.
        $this->postJson('/api/v1/auth/login', $this->loginPayload($user))->assertOk();
    }

    public function test_wrong_password_still_returns_401_before_geofence(): void
    {
        // L'ordre compte : un mauvais mot de passe reste un 401 « Identifiants
        // incorrects », jamais masqué derrière le contrôle de zone.
        $this->zone();
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Identifiants incorrects.');
    }

    public function test_login_otp_verify_path_is_also_enforced(): void
    {
        $this->zone();
        $user = $this->makeUser();

        // Pré-remplit le cache OTP comme le ferait `sendLoginOtp`.
        Cache::put('login_otp:'.mb_strtolower($user->email), [
            'code_hash' => Hash::make('246813'),
        ], now()->addMinutes(10));

        // OTP valide mais hors zone → 403 (le jeton n'est pas émis).
        $outside = $this->outsidePoint();
        $this->postJson('/api/v1/auth/login/otp/verify', [
            'email' => $user->email,
            'code' => '246813',
            'lat' => $outside['lat'],
            'lng' => $outside['lng'],
            'accuracy' => 40,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'OUTSIDE_GEOFENCE');

        // OTP valide et dans la zone → 200.
        $inside = $this->insidePoint();
        Cache::put('login_otp:'.mb_strtolower($user->email), [
            'code_hash' => Hash::make('246813'),
        ], now()->addMinutes(10));

        $this->postJson('/api/v1/auth/login/otp/verify', [
            'email' => $user->email,
            'code' => '246813',
            'lat' => $inside['lat'],
            'lng' => $inside['lng'],
            'accuracy' => 40,
        ])
            ->assertOk()
            ->assertJsonStructure(['jeton']);
    }
}
