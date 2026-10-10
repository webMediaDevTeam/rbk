<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\RingCentralService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Identifiants RingCentral **propres à une entreprise** — migration
 * `2026_10_08_120000_add_ringcentral_credentials_to_enterprises_table`.
 *
 *  - `Enterprise::getRingCentralCredentials()` : valeurs exclusivement
 *    stockées sur l'entreprise ;
 *  - `RingCentralService::configure()` : reconfigure la requête en cours
 *    (jeton + listes en cache isolés par compte) ;
 *  - `GET /call-logs/devices?enterprise_id=` : la sélection « Appareil /
 *    numéro source » des modales employé liste alors les appareils du
 *    compte de l'entreprise choisie ;
 *  - secret / jeton : jamais renvoyés par l'API (« enregistré » uniquement).
 */
class EnterpriseRingCentralCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'SUPER_ADMIN'): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role, 'status' => 'ACTIVE']));
    }

    /**
     * `Cache-Control` des endpoints « secrets » : Symfony trie les
     * directives (`must-revalidate, no-cache, no-store, private`) —
     * l'ordre n'est pas contractuel, seule la présence de `no-store` l'est.
     */
    private function assertNoStore(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }

    public function test_admin_stores_the_ringcentral_credentials_on_creation(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/entreprises', [
            'name' => 'Compte RC inc.',
            'ringcentral_client_id' => '  client-id-123  ',
            'ringcentral_client_secret' => 'secret-abc',
            'ringcentral_token' => 'jwt-xyz',
            'source' => 'RingCentral - Compte RC',
        ])->assertCreated();

        $enterpriseId = $response->json('data.entreprise.id');

        $this->assertDatabaseHas('enterprises', [
            'id' => $enterpriseId,
            'ringcentral_client_id' => 'client-id-123',
            'ringcentral_client_secret' => 'secret-abc',
            'ringcentral_token' => 'jwt-xyz',
            'source' => 'RingCentral - Compte RC',
        ]);

        $response
            ->assertJsonPath('data.entreprise.ringcentral_client_id', 'client-id-123')
            ->assertJsonPath('data.entreprise.source', 'RingCentral - Compte RC')
            ->assertJsonPath('data.entreprise.ringcentral_configured', true)
            ->assertJsonPath('data.entreprise.ringcentral_client_secret_set', true)
            ->assertJsonPath('data.entreprise.ringcentral_token_set', true)
            // Le secret et le jeton ne quittent jamais l'API.
            ->assertJsonMissingPath('data.entreprise.ringcentral_client_secret')
            ->assertJsonMissingPath('data.entreprise.ringcentral_token');
    }

    public function test_an_enterprise_can_be_created_without_any_ringcentral_field(): void
    {
        $this->admin();

        $this->postJson('/api/v1/entreprises', ['name' => 'Sans compte RC'])
            ->assertCreated()
            ->assertJsonPath('data.entreprise.ringcentral_client_id', null)
            ->assertJsonPath('data.entreprise.source', null)
            ->assertJsonPath('data.entreprise.ringcentral_configured', false)
            ->assertJsonPath('data.entreprise.ringcentral_client_secret_set', false);
    }

    public function test_update_keeps_replaces_and_clears_the_credentials(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'À mettre à jour',
            'ringcentral_client_id' => 'first-id',
            'ringcentral_client_secret' => 'first-secret',
            'ringcentral_token' => 'first-jwt',
            'source' => 'Source A',
        ]);

        // 1. Champs absents du payload → valeur enregistrée conservée.
        $this->putJson("/api/v1/entreprises/{$enterprise->id}", [
            'name' => 'Renommée',
            'ringcentral_client_id' => 'second-id',
        ])->assertOk();

        $enterprise->refresh();
        $this->assertSame('Renommée', $enterprise->name);
        $this->assertSame('second-id', $enterprise->ringcentral_client_id);
        $this->assertSame('first-secret', $enterprise->ringcentral_client_secret);
        $this->assertSame('first-jwt', $enterprise->ringcentral_token);
        $this->assertSame('Source A', $enterprise->source);

        // 2. Valeur non vide → remplacée.
        $this->putJson("/api/v1/entreprises/{$enterprise->id}", [
            'ringcentral_client_secret' => 'second-secret',
            'ringcentral_token' => 'second-jwt',
            'source' => 'Source B',
        ])->assertOk();

        $enterprise->refresh();
        $this->assertSame('second-secret', $enterprise->ringcentral_client_secret);
        $this->assertSame('second-jwt', $enterprise->ringcentral_token);
        $this->assertSame('Source B', $enterprise->source);

        // 3. `''` / `null` → compte d'entreprise retiré (retour au `.env`).
        $this->putJson("/api/v1/entreprises/{$enterprise->id}", [
            'ringcentral_client_id' => '',
            'ringcentral_client_secret' => null,
            'ringcentral_token' => '',
            'source' => '',
        ])->assertOk();

        $enterprise->refresh();
        $this->assertNull($enterprise->ringcentral_client_id);
        $this->assertNull($enterprise->ringcentral_client_secret);
        $this->assertNull($enterprise->ringcentral_token);
        $this->assertNull($enterprise->source);
        $this->assertFalse($enterprise->hasOwnRingCentralAccount());
    }

    public function test_ringcentral_fields_are_validated(): void
    {
        $this->admin();

        $this->postJson('/api/v1/entreprises', [
            'name' => 'Champs invalides',
            'source' => str_repeat('a', 256),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source']);
    }

    public function test_credentials_are_read_only_from_the_enterprise_record(): void
    {
        config()->set('services.ringcentral.client_id', 'env-client-id');
        config()->set('services.ringcentral.client_secret', 'env-client-secret');
        config()->set('services.ringcentral.jwt', 'env-jwt');

        // Les valeurs globales ne complètent pas une entreprise vide.
        $empty = (new Enterprise)->getRingCentralCredentials();
        $this->assertNull($empty['client_id']);
        $this->assertNull($empty['client_secret']);
        $this->assertNull($empty['token']);

        // Une entreprise partielle reste incomplète.
        $partial = new Enterprise(['ringcentral_client_id' => 'entreprise-id']);
        $credentials = $partial->getRingCentralCredentials();
        $this->assertSame('entreprise-id', $credentials['client_id']);
        $this->assertNull($credentials['client_secret']);
        $this->assertNull($credentials['token']);
        $this->assertFalse($partial->hasOwnRingCentralAccount());
    }

    public function test_devices_endpoint_uses_the_enterprise_credentials(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Compte propre',
            'ringcentral_client_id' => 'ent-client-id',
            'ringcentral_client_secret' => 'ent-client-secret',
            'ringcentral_token' => 'ent-jwt',
            'source' => 'Compte Entreprise',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->once()->with(Mockery::on(
            fn (array $credentials) => $credentials['client_id'] === 'ent-client-id'
                && $credentials['client_secret'] === 'ent-client-secret'
                && $credentials['token'] === 'ent-jwt'
                // La « source » n'est PAS un identifiant RingCentral.
                && ! array_key_exists('source', $credentials)
        ));
        $mock->shouldReceive('getDevices')->once()->with(100)->andReturn([
            ['id' => 'dev-ent', 'name' => 'Bureau', 'extension' => ['id' => 'ext-ent']],
        ]);
        $mock->shouldReceive('getPhoneNumbers')->once()->andReturn([
            ['phoneNumber' => '+15145550111', 'extension' => ['id' => 'ext-ent']],
        ]);

        $this->getJson("/api/v1/call-logs/devices?enterprise_id={$enterprise->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'dev-ent')
            ->assertJsonPath('data.0.phoneNumber', '+15145550111');
    }

    public function test_credentials_endpoint_returns_stored_values_without_cache_headers(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Credentials en base',
            'ringcentral_client_id' => 'stored-client-id',
            'ringcentral_client_secret' => 'stored-client-secret',
            'ringcentral_token' => 'stored-jwt',
        ]);

        $response = $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-credentials")
            ->assertOk()
            ->assertJsonPath('data.client_id', 'stored-client-id')
            ->assertJsonPath('data.client_secret', 'stored-client-secret')
            ->assertJsonPath('data.jwt', 'stored-jwt');

        $this->assertNoStore($response);
    }

    public function test_enterprise_credential_check_reports_success_without_exposing_secrets(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Compte RC vérifié',
            'ringcentral_client_id' => 'database-client-id',
            'ringcentral_client_secret' => 'database-secret',
            'ringcentral_token' => 'database-jwt',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->once()->with(Mockery::on(
            fn (array $credentials) => $credentials['client_id'] === 'database-client-id'
                && $credentials['client_secret'] === 'database-secret'
                && $credentials['token'] === 'database-jwt'
        ));
        $mock->shouldReceive('getAccount')->once()->andReturn([
            'id' => 'account-1',
            'companyName' => 'Entreprise RingCentral',
            'mainNumber' => '+15145550100',
            'status' => 'Confirmed',
            'type' => 'Company',
        ]);
        $mock->shouldReceive('getPhoneNumbers')->once()->with(1000)->andReturn([
            [
                'phoneNumber' => '+15145550101',
                'type' => 'Voice',
                'status' => 'Enabled',
                'extension' => ['id' => 'ext-1', 'extensionNumber' => '101'],
            ],
            [
                'phoneNumber' => '+15145550102',
                'type' => 'Voice',
                'status' => 'Enabled',
                'extension' => ['id' => 'ext-2', 'extensionNumber' => '102'],
            ],
        ]);
        // 3ᵉ appel du contrôle : `GET /account/~/extension`
        // (`type=User&status=Enabled&perPage=`) — postes de l'entreprise.
        $mock->shouldReceive('getAllUsers')->once()->with(100)->andReturn([
            [
                'id' => 'ext-1',
                'extensionNumber' => '101',
                'name' => 'Accueil',
                'status' => 'Enabled',
                'type' => 'User',
                'permissions' => ['ReadOnlyExtension'],
            ],
            [
                'id' => 'ext-2',
                'extensionNumber' => '102',
                'name' => 'Ventes',
                'status' => 'Enabled',
                'type' => 'User',
            ],
        ]);

        $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-status")
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.account.id', 'account-1')
            ->assertJsonPath('data.account.company_name', 'Entreprise RingCentral')
            ->assertJsonPath('data.phone_numbers.0.number', '+15145550101')
            ->assertJsonPath('data.phone_numbers.1.number', '+15145550102')
            ->assertJsonPath('data.extensions.0.extension_number', '101')
            ->assertJsonPath('data.extensions.0.name', 'Accueil')
            ->assertJsonPath('data.extensions.1.extension_number', '102')
            ->assertJsonPath('data.extensions_available', true)
            ->assertJsonMissingPath('data.client_secret')
            ->assertJsonMissingPath('data.token');
    }

    /** Un poste inaccessible n'invalide pas le compte : signalé seulement. */
    public function test_enterprise_credential_check_stays_active_when_extensions_fail(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Compte RC sans postes',
            'ringcentral_client_id' => 'database-client-id',
            'ringcentral_client_secret' => 'database-secret',
            'ringcentral_token' => 'database-jwt',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->once();
        $mock->shouldReceive('getAccount')->once()->andReturn(['id' => 'account-1']);
        $mock->shouldReceive('getPhoneNumbers')->once()->andReturn([]);
        $mock->shouldReceive('getAllUsers')->once()->andThrow(new Exception('Service down'));

        $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-status")
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.extensions', [])
            ->assertJsonPath('data.extensions_available', false)
            ->assertJsonPath('data.raw.extensions_error.message', 'Service down');
    }

    /** `GET /call-logs/users` (les postes) lit les identifiants **en base**. */
    public function test_users_endpoint_uses_the_enterprise_credentials(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Compte propre (postes)',
            'ringcentral_client_id' => 'ent-client-id',
            'ringcentral_client_secret' => 'ent-client-secret',
            'ringcentral_token' => 'ent-jwt',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->once()->with(Mockery::on(
            fn (array $credentials) => $credentials['client_id'] === 'ent-client-id'
                && $credentials['client_secret'] === 'ent-client-secret'
                && $credentials['token'] === 'ent-jwt'
        ));
        $mock->shouldReceive('getAllUsers')->once()->with(100)->andReturn([
            ['id' => 'ext-ent', 'name' => 'Poste', 'extensionNumber' => '110'],
        ]);

        $this->getJson("/api/v1/call-logs/users?enterprise_id={$enterprise->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'ext-ent')
            ->assertJsonPath('data.0.extensionNumber', '110');
    }

    /** Pas de repli silencieux sur le `.env` : 422 tant que c'est incomplet. */
    public function test_users_endpoint_rejects_enterprise_without_complete_database_credentials(): void
    {
        $this->admin('ADMIN');
        config()->set('services.ringcentral.client_id', 'env-client-id');
        config()->set('services.ringcentral.client_secret', 'env-client-secret');
        config()->set('services.ringcentral.jwt', 'env-jwt');
        $enterprise = Enterprise::create(['name' => 'Sans compte RingCentral']);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->never();
        $mock->shouldReceive('getAllUsers')->never();

        $this->getJson("/api/v1/call-logs/users?enterprise_id={$enterprise->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['enterprise_id']);
    }

    public function test_enterprise_credential_check_reports_inactive_when_ringcentral_auth_fails(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Compte RC inaccessible',
            'ringcentral_client_id' => 'database-client-id',
            'ringcentral_client_secret' => 'database-secret',
            'ringcentral_token' => 'database-jwt',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->once();
        $mock->shouldReceive('getAccount')->once()->andThrow(new Exception('Private credential error'));

        $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-status")
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.reason', 'connection_failed')
            ->assertJsonMissingPath('data.error');
    }

    /** L'endpoint des choix exige une entreprise en base. */
    public function test_devices_endpoint_requires_an_enterprise_id(): void
    {
        $this->admin('ADMIN');

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->never();
        $mock->shouldReceive('getDevices')->never();
        $mock->shouldReceive('getPhoneNumbers')->never();

        $this->getJson('/api/v1/call-logs/devices')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['enterprise_id']);
    }

    public function test_devices_endpoint_rejects_enterprise_without_complete_database_credentials(): void
    {
        $this->admin('ADMIN');
        config()->set('services.ringcentral.client_id', 'env-client-id');
        config()->set('services.ringcentral.client_secret', 'env-client-secret');
        config()->set('services.ringcentral.jwt', 'env-jwt');
        $enterprise = Enterprise::create(['name' => 'Sans compte RingCentral']);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->never();
        $mock->shouldReceive('getDevices')->never();

        $this->getJson("/api/v1/call-logs/devices?enterprise_id={$enterprise->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette entreprise doit avoir son client ID, son secret et son JWT RingCentral enregistrés.');
    }

    public function test_devices_endpoint_rejects_an_unknown_enterprise(): void
    {
        $this->admin('ADMIN');

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('getDevices')->never();

        $this->getJson('/api/v1/call-logs/devices?enterprise_id='.Str::uuid())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['enterprise_id']);
    }

    /** Les secrets restent réservés à ADMIN / SUPER_ADMIN (routes entreprises). */
    public function test_a_commercial_cannot_read_or_write_the_credentials(): void
    {
        $this->admin('COMERCIAL');
        $enterprise = Enterprise::create([
            'name' => 'Entrepriese protégée',
            'ringcentral_client_id' => 'hidden-id',
        ]);

        $this->getJson('/api/v1/entreprises')->assertForbidden();
        $this->getJson("/api/v1/entreprises/{$enterprise->id}")->assertForbidden();
        $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-credentials")->assertForbidden();
        $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-token")->assertForbidden();
        $this->putJson("/api/v1/entreprises/{$enterprise->id}", [
            'ringcentral_client_id' => 'pirate-id',
        ])->assertForbidden();

        $this->assertSame('hidden-id', $enterprise->refresh()->ringcentral_client_id);
    }

    // ── Onglet « RingCentral » de la fiche entreprise ─────────────────────

    /** `GET /entreprises/{id}/ringcentral-token` : JWT échangé, métadonnées masquées. */
    public function test_ringcentral_token_endpoint_returns_masked_token_metadata(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Compte RC token',
            'ringcentral_client_id' => 'token-client-id',
            'ringcentral_client_secret' => 'token-secret',
            'ringcentral_token' => 'token-jwt',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        // L'échange part des identifiants **en base**, jamais du `.env`.
        $mock->shouldReceive('configure')->once()->with(Mockery::on(
            fn (array $credentials) => $credentials['client_id'] === 'token-client-id'
                && $credentials['client_secret'] === 'token-secret'
                && $credentials['token'] === 'token-jwt'
        ));
        $mock->shouldReceive('accessTokenInfo')->once()->andReturn([
            'access_token' => 'eyJhbGciOiJS…a1b2c3',
            'token_type' => 'bearer',
            'expire_time' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'expires_in' => 3600,
        ]);

        $response = $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-token")
            ->assertOk()
            ->assertJsonPath('data.access_token', 'eyJhbGciOiJS…a1b2c3')
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.expires_in', 3600)
            // Le secret / le JWT de l'entreprise ne figurent jamais ici.
            ->assertJsonMissingPath('data.client_secret')
            ->assertJsonMissingPath('data.jwt');

        $this->assertNoStore($response);
    }

    /** Sans compte complet en base, aucun échange n'est tenté (422). */
    public function test_ringcentral_token_endpoint_requires_complete_credentials(): void
    {
        $this->admin('ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'Compte RC incomplet',
            'ringcentral_client_id' => 'only-id',
        ]);

        $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-token")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /** JWT refusé par RingCentral → 502 + détail (erreur + indice). */
    public function test_ringcentral_token_endpoint_returns_502_when_the_exchange_fails(): void
    {
        $this->admin('SUPER_ADMIN');
        $enterprise = Enterprise::create([
            'name' => 'JWT refusé',
            'ringcentral_client_id' => 'refused-id',
            'ringcentral_client_secret' => 'refused-secret',
            'ringcentral_token' => 'refused-jwt',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('configure')->once();
        $mock->shouldReceive('accessTokenInfo')->once()->andThrow(new Exception('invalid_grant'));

        $this->getJson("/api/v1/entreprises/{$enterprise->id}/ringcentral-token")
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.message', 'invalid_grant');
    }
}
