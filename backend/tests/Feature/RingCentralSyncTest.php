<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\CallRecording;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\User;
use App\Services\RingCentralService;
use App\Services\RingCentralSyncService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Passage au réel RingCentral (docs/TODOS.md « Sync Users » +
 * « Sync Call Logs ») :
 *
 *   POST /call-logs/sync/employees                — correspondance employé
 *                                                    ↔ poste + numéros
 *                                                    (`employees.ringcentral_*`) ;
 *   POST /call-logs/my-call                       — appel sortant du
 *                                                    COMERCIAL, journal ouvert
 *                                                    immédiatement + enregistrement.
 *
 * Les journaux enrichis par la synchro sont couverts par
 * `EmployeeCallLogsTest`.
 */
class RingCentralSyncTest extends TestCase
{
    use RefreshDatabase;

    private function mockService(): RingCentralService
    {
        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);

        return $mock;
    }

    /** Fiche employé — `device`/`from` facultatifs (jamais imposés). */
    private function employe(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);

        $user->employee()->create(array_merge([
            'first_name' => 'Jean',
            'last_name' => 'Tremblay',
        ], $attributes));

        return $user;
    }

    private function reserveClient(User $user): Client
    {
        $client = Client::create([
            'name' => 'Call target',
            'phone' => '+15145550001',
            'status' => Client::STATUS_RESERVED,
        ]);
        Reservation::create([
            'client_id' => $client->id,
            'comercial_id' => $user->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        return $client;
    }

    /** Les trois listes RingCentral requises par la synchronisation. */
    private function stubLists(array $extensions, array $devices, array $phoneNumbers): void
    {
        $mock = $this->mockService();
        $mock->shouldReceive('getAllUsers')->atLeast()->once()->with(250)->andReturn($extensions);
        $mock->shouldReceive('getDevices')->atLeast()->once()->with(250)->andReturn($devices);
        $mock->shouldReceive('getPhoneNumbers')->atLeast()->once()->with(500)->andReturn($phoneNumbers);
    }

    public function test_unauthenticated_cannot_synchronize(): void
    {
        $this->postJson('/api/v1/call-logs/sync/employees')->assertUnauthorized();
    }

    public function test_commercial_cannot_synchronize(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']));

        $this->postJson('/api/v1/call-logs/sync/employees')->assertForbidden();
    }

    // ── Phase 1 : employés ↔ postes / numéros ────────────────────────────

    public function test_mapping_is_stored_from_the_device_of_the_fiche(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe([
            'ringcentral_device_id' => 'dev-9',
            'ringcentral_from_number' => '+15146120498',
        ]);

        $this->stubLists(
            [(object) ['id' => 'ext-101', 'extensionNumber' => '101']],
            [['id' => 'dev-9', 'extensionNumber' => '101', 'extension' => ['id' => 'ext-101']]],
            [
                ['phoneNumber' => '+15146120498', 'primary' => true, 'extension' => ['id' => 'ext-101']],
                ['phoneNumber' => '+15146120499', 'primary' => false, 'extension' => ['id' => 'ext-101']],
                // Numéro d'un **autre** poste : ne doit pas fuiter.
                ['phoneNumber' => '+15145550000', 'primary' => true, 'extension' => ['id' => 'ext-200']],
            ]
        );

        $this->postJson('/api/v1/call-logs/sync/employees')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.unmatched', []);

        $employee = $employe->employee->refresh();

        $this->assertSame('ext-101', $employee->ringcentral_extension_id);
        $this->assertSame('101', $employee->ringcentral_extension_number);
        $this->assertSame(['+15146120498', '+15146120499'], $employee->ringcentral_phone_numbers);
        $this->assertSame('dev-9', $employee->ringcentral_device_id);
        $this->assertSame('+15146120498', $employee->ringcentral_from_number);
        $this->assertNotNull($employee->ringcentral_synced_at);
    }

    public function test_mapping_fills_the_call_source_when_the_fiche_is_empty(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe(); // ni appareil ni numéro source

        $this->stubLists(
            [(object) ['id' => 'ext-101', 'extensionNumber' => '101', 'contact' => (object) ['email' => $employe->email]]],
            [['id' => 'dev-42', 'extensionNumber' => '101', 'extension' => ['id' => 'ext-101']]],
            [['phoneNumber' => '+15146120498', 'primary' => true, 'extension' => ['id' => 'ext-101']]]
        );

        $this->postJson('/api/v1/call-logs/sync/employees')
            ->assertOk()
            ->assertJsonPath('data.matched', 1);

        $employee = $employe->employee->refresh();

        // La sélection « Appareil / numéro source » des modales est
        // préremplie par la synchro.
        $this->assertSame('dev-42', $employee->ringcentral_device_id);
        $this->assertSame('+15146120498', $employee->ringcentral_from_number);
        $this->assertSame('ext-101', $employee->ringcentral_extension_id);
    }

    public function test_mapping_matches_by_phone_number_when_no_device_nor_email(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        // Aucun appareil ; e-mail local sans correspondance RingCentral.
        $employe = $this->employe(['phone' => '+1 (514) 612-0498']);

        $this->stubLists(
            [(object) ['id' => 'ext-303', 'extensionNumber' => '303']],
            [],
            // Numéro stocké « nu » côté RingCentral : la comparaison ignore
            // les formats (`+1 (514) 612-0498` = `15146120498`).
            [['phoneNumber' => '5146120498', 'extension' => ['id' => 'ext-303']]]
        );

        $this->postJson('/api/v1/call-logs/sync/employees')
            ->assertOk()
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.unmatched', []);

        $this->assertSame('ext-303', $employe->employee->refresh()->ringcentral_extension_id);
    }

    public function test_unmatched_employees_are_reported_and_left_untouched(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe([
            'ringcentral_device_id' => 'dev-inconnu',
            'phone' => '+15145550001',
        ]);

        $this->stubLists(
            [(object) ['id' => 'ext-101', 'extensionNumber' => '101']],
            [['id' => 'autre-dev', 'extension' => ['id' => 'ext-101']]],
            [['phoneNumber' => '+15146120498', 'extension' => ['id' => 'ext-101']]]
        );

        $this->postJson('/api/v1/call-logs/sync/employees')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.matched', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.unmatched.0.user_id', $employe->id)
            ->assertJsonPath('data.unmatched.0.email', $employe->email);

        $employee = $employe->employee->refresh();

        $this->assertNull($employee->ringcentral_extension_id);
        $this->assertNull($employee->ringcentral_synced_at);
        $this->assertSame('dev-inconnu', $employee->ringcentral_device_id, 'Le choix manuel n\'est jamais écrasé.');
    }

    public function test_sync_employees_failure_returns_502(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $mock = $this->mockService();
        $mock->shouldReceive('getAllUsers')->once()->andThrow(new Exception('RingCentral non configuré'));

        $this->postJson('/api/v1/call-logs/sync/employees')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error']);
    }

    // ── Phase 2 : appel sortant enregistré ────────────────────────────────

    public function test_outbound_call_opens_the_journal_and_stores_the_recording(): void
    {
        $employe = $this->employe([
            'ringcentral_device_id' => 'dev-9',
            'ringcentral_from_number' => '+15146120498',
            'ringcentral_extension_id' => 'ext-9',
        ]);
        $client = $this->reserveClient($employe);
        Sanctum::actingAs($employe);

        $mock = $this->mockService();
        $mock->shouldReceive('makeCallOut')
            ->once()
            ->with('+15145550001', '+15146120498', 'dev-9', null)
            ->andReturn(['session' => [
                'id' => 's-9',
                'parties' => [['id' => 'p-1', 'status' => ['code' => 'Setup']]],
            ]]);
        $mock->shouldReceive('startRecording')
            ->once()
            ->with('s-9', 'p-1')
            ->andReturn(['id' => 'REC-9', 'uri' => 'https://platform.ringcentral.com/…/recording/REC-9']);

        $response = $this->postJson('/api/v1/call-logs/my-call', [
            'client_id' => $client->id,
            'to' => '+15145550001',
            'record' => true,
        ])->assertOk();

        $callLogId = $response->json('data.call_log_id');
        $this->assertNotNull($callLogId, 'L\'appel doit ouvrir une ligne de journal.');
        $this->assertSame('s-9', $response->json('data.session_id'));
        $this->assertTrue($response->json('data.recorded'));

        $call = CallLog::query()->findOrFail($callLogId);
        $this->assertSame($employe->employee->id, $call->employee_id);
        $this->assertSame($client->id, $call->client_id);
        $this->assertSame('Outbound', $call->direction);
        $this->assertSame('+15146120498', $call->from_number);
        $this->assertSame('+15145550001', $call->to_number);
        $this->assertSame('s-9', $call->ringcentral_session_id);

        // L'enregistrement est lisible immédiatement (pas d'attente de synchro).
        $recording = CallRecording::query()->where('call_log_id', $call->id)->firstOrFail();
        $this->assertSame('REC-9', $recording->ringcentral_recording_id);
    }

    public function test_outbound_call_still_succeeds_when_the_journal_cannot_be_written(): void
    {
        $employe = $this->employe([
            'ringcentral_device_id' => 'dev-9',
            'ringcentral_from_number' => '+15146120498',
            'ringcentral_extension_id' => 'ext-9',
        ]);
        $client = $this->reserveClient($employe);
        Sanctum::actingAs($employe);

        $mock = $this->mockService();
        $mock->shouldReceive('makeCallOut')->once()->andReturn([
            'session' => ['id' => 's-10', 'parties' => [['id' => 'p-2']]],
        ]);
        // L'enregistrement démarre, mais sa persistance devient impossible.
        $mock->shouldReceive('startRecording')->once()->andReturn(['id' => 'REC-10', 'uri' => '…/REC-10']);
        $sync = Mockery::mock(RingCentralSyncService::class);
        $sync->shouldReceive('recordOutboundCall')->once()->andReturn(null);
        $this->app->instance(RingCentralSyncService::class, $sync);

        $this->postJson('/api/v1/call-logs/my-call', [
            'client_id' => $client->id,
            'to' => '+15145550001',
        ])
            ->assertOk()
            ->assertJsonPath('data.session_id', 's-10')
            ->assertJsonPath('data.call_log_id', null)
            ->assertJsonPath('data.recorded', true);
    }
}
