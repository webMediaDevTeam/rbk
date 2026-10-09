<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\RingCentralService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * API de contrôle d'appel RingCentral (phase de test Super Admin,
 * page `/call-logs-test`) : RBAC, validation et pass-through du service —
 * **aucune écriture en base** à ce stade.
 */
class RingCentralApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']);
    }

    /** Service facturé branché sur le conteneur (aucun appel réseau réel). */
    private function mockService(): RingCentralService
    {
        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);

        return $mock;
    }

    /** stdClass comme le vrai SDK (les records RingCentral sont des objets). */
    private function obj(array $data): object
    {
        return json_decode(json_encode($data));
    }

    public function test_unauthenticated_cannot_use_telephony_endpoints(): void
    {
        $this->getJson('/api/v1/call-logs/account')->assertUnauthorized();
        $this->getJson('/api/v1/call-logs/devices')->assertUnauthorized();
        $this->postJson('/api/v1/call-logs/call', ['to' => '15145550123'])->assertUnauthorized();
        $this->getJson('/api/v1/call-logs/calls/sess-1')->assertUnauthorized();
        $this->postJson('/api/v1/call-logs/calls/sess-1/parties/party-1/record')->assertUnauthorized();
        $this->getJson('/api/v1/call-logs/calls/sess-1/parties/party-1/recordings')->assertUnauthorized();
        $this->deleteJson('/api/v1/call-logs/calls/sess-1')->assertUnauthorized();
    }

    public function test_commercial_cannot_use_telephony_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/call-logs/account')->assertStatus(403);
        $this->getJson('/api/v1/call-logs/users')->assertStatus(403);
        $this->postJson('/api/v1/call-logs/call', ['to' => '15145550123'])->assertStatus(403);
        $this->deleteJson('/api/v1/call-logs/calls/sess-1')->assertStatus(403);
    }

    public function test_ringcentral_user_list_returns_enabled_extension_records_to_admins(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('getAllUsers')->once()->with(50)->andReturn([
            $this->obj([
                'id' => 'extension-50',
                'name' => 'Remote User',
                'extensionNumber' => '50',
                'contact' => ['email' => 'remote@example.test', 'phoneNumber' => '+15145550100'],
            ]),
        ]);

        $this->getJson('/api/v1/call-logs/users?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'extension-50')
            ->assertJsonPath('data.0.contact.email', 'remote@example.test');
    }

    public function test_account_returns_company_information(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('getAccount')->once()->andReturn([
            'id' => '4711480022',
            'company' => ['name' => 'Acme Téléphonie'],
        ]);

        $this->getJson('/api/v1/call-logs/account')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', '4711480022')
            ->assertJsonPath('data.company.name', 'Acme Téléphonie');
    }

    public function test_service_failure_returns_502(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('getAccount')->once()->andThrow(new Exception('RingCentral non configuré'));

        $this->getJson('/api/v1/call-logs/account')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error']);
    }

    public function test_devices_returns_records_with_their_phone_numbers(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $enterprise = Enterprise::create([
            'name' => 'Compte RC test',
            'ringcentral_client_id' => 'client-id',
            'ringcentral_client_secret' => 'client-secret',
            'ringcentral_token' => 'jwt-token',
        ]);

        $mock = $this->mockService();
        $mock->shouldReceive('configure')->once();
        // Les softphones ont `phoneLines: []` : le numéro vient de
        // `/account/~/phone-number`, rattaché par `extension.id`.
        $mock->shouldReceive('getDevices')->once()->with(50)->andReturn([
            $this->obj(['id' => 'dev-1', 'name' => 'Bureau', 'extension' => ['id' => 'ext-101']]),
            $this->obj(['id' => 'dev-2', 'name' => 'Softphone orphelin']),
        ]);
        $mock->shouldReceive('getPhoneNumbers')->once()->with(1000)->andReturn([
            ['phoneNumber' => '+15145550100', 'extension' => ['id' => 'ext-101']],
        ]);

        $this->getJson("/api/v1/call-logs/devices?per_page=50&enterprise_id={$enterprise->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'dev-1')
            ->assertJsonPath('data.0.phoneNumbers.0', '+15145550100')
            ->assertJsonPath('data.0.phoneNumber', '+15145550100')
            // Aucun numéro rattaché → libellé de secours = nom de l'appareil.
            ->assertJsonPath('data.1.phoneNumbers', [])
            ->assertJsonPath('data.1.phoneNumber', null);
    }

    public function test_devices_survive_an_unreachable_phone_number_api(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $enterprise = Enterprise::create([
            'name' => 'Compte RC test',
            'ringcentral_client_id' => 'client-id',
            'ringcentral_client_secret' => 'client-secret',
            'ringcentral_token' => 'jwt-token',
        ]);

        $mock = $this->mockService();
        $mock->shouldReceive('configure')->once();
        $mock->shouldReceive('getDevices')->once()->andReturn([
            ['id' => 'dev-1', 'name' => 'Bureau', 'extension' => ['id' => 'ext-101']],
        ]);
        $mock->shouldReceive('getPhoneNumbers')->once()->andThrow(new Exception('RingCentral non configuré'));

        $this->getJson("/api/v1/call-logs/devices?enterprise_id={$enterprise->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'dev-1');
    }

    public function test_make_call_requires_destination(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/v1/call-logs/call', ['device_id' => 'dev-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_make_call_requires_a_source(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // ⚠️ Le contrôleur résout la source par défaut via `getMyExtension()` :
        // sans mock, une suite qui répond (JWT valide) **déclencherait un vrai
        // appel**. On force donc l'échec de résolution → 422, sans réseau.
        // (Le pendant « source résolue » est couvert par
        // `test_make_call_defaults_to_session_extension`.)
        $mock = $this->mockService();
        $mock->shouldReceive('getMyExtension')->once()->andThrow(new Exception('RingCentral non configuré'));
        $mock->shouldNotReceive('makeCallOut');

        $this->postJson('/api/v1/call-logs/call', ['to' => '15145550123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_id']);
    }

    public function test_make_call_returns_session_id(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('makeCallOut')
            ->once()
            ->with('15145550123', '15145559999', 'dev-1', null)
            ->andReturn([
                'uri' => 'https://platform.ringcentral.com/restapi/v1.0/account/1/telephony/session/sess-42',
                'parties' => [['id' => 'party-7']],
            ]);

        $this->postJson('/api/v1/call-logs/call', [
            'to' => '15145550123',
            'from' => '15145559999',
            'device_id' => 'dev-1',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', 'sess-42')
            ->assertJsonPath('data.party_id', 'party-7')
            ->assertJsonPath('data.to', '15145550123');
    }

    public function test_make_call_resolves_extension_and_device_of_a_local_user(): void
    {
        $commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        // 1. extension RingCentral dont l'e-mail == e-mail de l'employé
        $mock->shouldReceive('getAllUsers')->once()->with(250)->andReturn([
            $this->obj(['id' => 'ext-101', 'extensionNumber' => '101', 'contact' => ['email' => $commercial->email]]),
        ]);
        // 2. appareil rattaché à cette extension
        $mock->shouldReceive('getDevices')->once()->with(250)->andReturn([
            $this->obj(['id' => 'dev-9', 'extension' => ['id' => 'ext-999']]),
            $this->obj(['id' => 'dev-42', 'extension' => ['id' => 'ext-101']]),
        ]);
        // 3. appel sortant depuis cette extension (+ son appareil)
        $mock->shouldReceive('makeCallOut')
            ->once()
            ->with('15145550123', null, 'dev-42', 'ext-101')
            ->andReturn(['sessionId' => 'sess-42']);

        $this->postJson('/api/v1/call-logs/call', [
            'to' => '15145550123',
            'user_id' => $commercial->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.session_id', 'sess-42')
            ->assertJsonPath('data.device_id', 'dev-42')
            ->assertJsonPath('data.extension_id', 'ext-101');
    }

    /**
     * Sans aucune source explicite, la source par défaut est **l'extension
     * de la session d'authentification** — la seule acceptée par RingCentral
     * dans `from.extensionId`.
     */
    public function test_make_call_defaults_to_session_extension(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('getMyExtension')->once()->andReturn(['id' => '217943024']);
        $mock->shouldReceive('makeCallOut')
            ->once()
            ->with('15145550123', null, null, '217943024')
            ->andReturn(['session' => ['id' => 'sess-42', 'parties' => [['id' => 'party-1']]]]);

        $this->postJson('/api/v1/call-logs/call', ['to' => '15145550123'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', 'sess-42')
            ->assertJsonPath('data.party_id', 'party-1')
            ->assertJsonPath('data.extension_id', '217943024');
    }

    public function test_make_call_fails_when_user_has_no_ringcentral_extension(): void
    {
        $commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('getAllUsers')->once()->andReturn([
            $this->obj(['id' => 'ext-202', 'contact' => ['email' => 'autre@exemple.ca']]),
        ]);
        $mock->shouldReceive('getDevices')->never();

        $this->postJson('/api/v1/call-logs/call', [
            'to' => '15145550123',
            'user_id' => $commercial->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_id']);
    }

    public function test_call_status_returns_parties(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('getCallSession')->once()->with('sess-1')->andReturn([
            'id' => 'sess-1',
            'status' => 'Started',
            'parties' => [
                ['id' => 'party-1', 'status' => 'Connected', 'direction' => 'Outbound',
                    'from' => ['phoneNumber' => '15145559999'], 'to' => ['phoneNumber' => '15145550123']],
            ],
        ]);

        $this->getJson('/api/v1/call-logs/calls/sess-1')
            ->assertOk()
            ->assertJsonPath('data.session_id', 'sess-1')
            ->assertJsonPath('data.status', 'Started')
            ->assertJsonPath('data.parties.0.id', 'party-1')
            ->assertJsonPath('data.parties.0.to', '15145550123');
    }

    public function test_call_status_rejects_invalid_session_id(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/call-logs/calls/bad$id')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sessionId']);
    }

    public function test_record_starts_a_recording(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('startRecording')->once()->with('sess-1', 'party-1')->andReturn([
            'id' => 'rec-1',
            'status' => 'InProgress',
        ]);

        $this->postJson('/api/v1/call-logs/calls/sess-1/parties/party-1/record')
            ->assertOk()
            ->assertJsonPath('data.session_id', 'sess-1')
            ->assertJsonPath('data.party_id', 'party-1')
            ->assertJsonPath('data.raw.id', 'rec-1');
    }

    public function test_recordings_are_listed(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('getRecordings')->once()->with('sess-1', 'party-1')->andReturn([
            ['id' => 'rec-1', 'duration' => 42],
        ]);

        $this->getJson('/api/v1/call-logs/calls/sess-1/parties/party-1/recordings')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.recordings.0.id', 'rec-1');
    }

    public function test_hangup_ends_the_session(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $mock = $this->mockService();
        $mock->shouldReceive('hangUpSession')->once()->with('sess-1')->andReturn([]);

        $this->deleteJson('/api/v1/call-logs/calls/sess-1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', 'sess-1');
    }
}
