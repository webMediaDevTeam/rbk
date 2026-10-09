<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\RingCentralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Identifiants RingCentral **propres à une entreprise** — migration
 * `2026_10_08_120000_add_ringcentral_credentials_to_enterprises_table`.
 *
 *  - `Enterprise::getRingCentralCredentials()` : valeurs d'entreprise avec
 *    repli champ par champ sur `.env` (`services.ringcentral.*`) ;
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
        $this->putJson("/api/v1/entreprises/{$enterprise->id}", [
            'ringcentral_client_id' => 'pirate-id',
        ])->assertForbidden();

        $this->assertSame('hidden-id', $enterprise->refresh()->ringcentral_client_id);
    }
}
