<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\CallRecording;
use App\Models\User;
use App\Services\RingCentralService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Onglet « Appels » de la fiche employé (`/comercialDetail/:id`,
 * docs/TODOS.md « Onglet Appels » + « Sync Call Logs ») :
 *
 *   GET  /call-logs/employees/{id}/logs       — journal **stocké** en base
 *                                              (`call_logs`, lecture locale :
 *                                              aucune API à l'ouverture) ;
 *   POST /call-logs/employees/{id}/logs/sync  — récupération RingCentral ;
 *   GET  /call-logs/recordings/{id}/content   — proxy audio.
 *
 * Les trois sont réservés ADMIN + SUPER_ADMIN.
 */
class EmployeeCallLogsTest extends TestCase
{
    use RefreshDatabase;

    /** Service facturé branché sur le conteneur (aucun appel réseau réel). */
    private function mockService(): RingCentralService
    {
        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);

        return $mock;
    }

    /** Cible : utilisateur COMERCIAL + sa fiche `employees` 1:1. */
    private function employe(string $deviceId = 'dev-9'): User
    {
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);

        $user->employee()->create([
            'first_name' => 'Jean',
            'last_name' => 'Tremblay',
            'ringcentral_device_id' => $deviceId,
            'ringcentral_from_number' => '+15146120498',
        ]);

        return $user;
    }

    /** Ligne du journal déjà en base (ce que produit la synchro). */
    private function appel(User $employe, array $over = []): CallLog
    {
        return CallLog::create(array_merge([
            'employee_id' => $employe->employee->id,
            'ringcentral_call_id' => 'c1',
            'ringcentral_session_id' => 's-1',
            'direction' => 'Outbound',
            'type' => 'Voice',
            'from_number' => '+15146120498',
            'to_number' => '+15145550001',
            'started_at' => '2026-10-06T10:08:10Z',
            'duration' => 45,
            'result' => 'Accepted',
        ], $over));
    }

    public function test_unauthenticated_cannot_read_logs_or_recordings(): void
    {
        $this->getJson('/api/v1/call-logs/employees/1/logs')->assertUnauthorized();
        $this->getJson('/api/v1/call-logs/recordings/REC-1/content')->assertUnauthorized();
        $this->postJson('/api/v1/call-logs/employees/1/logs/sync')->assertUnauthorized();
        $this->postJson('/api/v1/call-logs/sync/employees')->assertUnauthorized();
    }

    public function test_commercial_cannot_read_or_sync_the_journal_of_an_employee(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/call-logs/employees/1/logs')->assertForbidden();
        $this->getJson('/api/v1/call-logs/recordings/REC-1/content')->assertForbidden();
        $this->postJson('/api/v1/call-logs/employees/1/logs/sync')->assertForbidden();
        $this->postJson('/api/v1/call-logs/sync/employees')->assertForbidden();
    }

    // ── Lecture locale ────────────────────────────────────────────────────

    public function test_admin_reads_the_stored_journal_without_calling_the_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe();

        $call = $this->appel($employe);
        CallRecording::create([
            'call_log_id' => $call->id,
            'ringcentral_recording_id' => 'REC-1',
            'type' => 'Automatic',
            'duration' => 40,
        ]);
        // Une seconde ligne **sans** enregistrement reste affichable.
        $this->appel($employe, [
            'ringcentral_call_id' => 'c2',
            'ringcentral_session_id' => 's-2',
            'direction' => 'Inbound',
            'duration' => 12,
            'result' => 'Missed',
            'started_at' => '2026-10-05T09:00:00Z',
        ]);

        // Aucun appel réseau : le journal vient de `call_logs`.
        $mock = $this->mockService();
        $mock->shouldNotReceive('getDevices');
        $mock->shouldNotReceive('getAllUsers');
        $mock->shouldNotReceive('getCallHistoryByUser');

        $this->getJson("/api/v1/call-logs/employees/{$employe->id}/logs")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.source', 'db')
            ->assertJsonPath('data.extension_id', null)
            ->assertJsonPath('data.device_id', 'dev-9')
            ->assertJsonPath('data.from_number', '+15146120498')
            // Le plus récent d'abord.
            ->assertJsonPath('data.records.0.id', 'c1')
            ->assertJsonPath('data.records.0.recording.id', 'REC-1')
            ->assertJsonPath('data.records.0.recording.duration', 40)
            ->assertJsonPath('data.records.0.duration', 45)
            ->assertJsonPath('data.records.0.result', 'Accepted')
            ->assertJsonPath('data.records.0.from.phoneNumber', '+15146120498')
            ->assertJsonPath('data.records.0.to.phoneNumber', '+15145550001')
            ->assertJsonPath('data.records.1.id', 'c2')
            ->assertJsonPath('data.records.1.recording', null)
            ->assertJsonPath('data.records.1.direction', 'Inbound');
    }

    public function test_empty_journal_returns_an_empty_list_not_an_error(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe('dev-inconnu');

        $this->getJson("/api/v1/call-logs/employees/{$employe->id}/logs")
            ->assertOk()
            ->assertJsonPath('data.records', []);
    }

    public function test_user_without_an_employee_file_gets_a_422(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/call-logs/employees/{$admin->id}/logs")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Aucune fiche employé rattachée à cet utilisateur.');

        $this->postJson("/api/v1/call-logs/employees/{$admin->id}/logs/sync")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Aucune fiche employé rattachée à cet utilisateur.');
    }

    // ── Synchro du journal (écriture en base) ─────────────────────────────

    public function test_sync_stores_the_journal_and_deduplicates_on_second_run(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe('dev-9');

        $mock = $this->mockService();
        // 1. L'appareil de la fiche donne le poste (aucune recherche par e-mail).
        $mock->shouldReceive('getDevices')->once()->with(250)->andReturn([
            ['id' => 'dev-9', 'extensionNumber' => '101', 'extension' => ['id' => 'ext-101']],
        ]);
        $mock->shouldNotReceive('getAllUsers');
        // 2. Journal de ce poste, avec enregistrement.
        $mock->shouldReceive('getCallHistoryByUser')
            ->twice()
            ->with('ext-101', Mockery::type('array'))
            ->andReturn([
                ['id' => 'c1', 'sessionId' => 's-1', 'direction' => 'Outbound', 'duration' => 45,
                    'startTime' => '2026-10-06T10:08:10Z', 'result' => 'Accepted',
                    'from' => ['phoneNumber' => '+15146120498'],
                    'to' => ['phoneNumber' => '+15145550001'],
                    'recording' => ['id' => 'REC-1', 'type' => 'Automatic', 'duration' => 40,
                        'contentUri' => 'https://media.ringcentral.com/REC-1/content']],
            ]);

        $this->postJson("/api/v1/call-logs/employees/{$employe->id}/logs/sync")
            ->assertOk()
            ->assertJsonPath('data.sync.extension_id', 'ext-101')
            ->assertJsonPath('data.sync.resolved_by', 'device')
            ->assertJsonPath('data.sync.fetched', 1)
            ->assertJsonPath('data.sync.created', 1)
            ->assertJsonPath('data.sync.updated', 0)
            ->assertJsonPath('data.source', 'db')
            ->assertJsonPath('data.records.0.id', 'c1')
            ->assertJsonPath('data.records.0.recording.id', 'REC-1');

        $this->assertSame(1, CallLog::query()->count());
        $this->assertSame(1, CallRecording::query()->count());
        $this->assertSame(45, CallLog::query()->first()->duration);
        $this->assertSame(
            'https://media.ringcentral.com/REC-1/content',
            CallRecording::query()->first()->content_uri
        );

        // Le poste est désormais connu de la fiche : la 2ᵉ synchro ne
        // rebondit plus sur `/device` (quota CMN-301).
        $second = $this->postJson("/api/v1/call-logs/employees/{$employe->id}/logs/sync")
            ->assertOk()
            ->assertJsonPath('data.sync.resolved_by', 'stored')
            ->assertJsonPath('data.sync.created', 0)
            ->assertJsonPath('data.sync.updated', 1);

        $this->assertSame(1, CallLog::query()->count(), 'Le dé-doublonnage empêche la seconde écriture.');
        $this->assertSame(1, CallRecording::query()->count());
        $this->assertSame('ext-101', $employe->employee->refresh()->ringcentral_extension_id);
        $this->assertNotNull($second->json('data.records.0.recording.id'));
    }

    public function test_sync_falls_back_to_the_email_when_the_device_is_unknown(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe('dev-inconnu');

        $mock = $this->mockService();
        $mock->shouldReceive('getDevices')->once()->andReturn([
            ['id' => 'autre-dev', 'extensionNumber' => '200', 'extension' => ['id' => 'ext-200']],
        ]);
        $mock->shouldReceive('getAllUsers')->once()->with(250)->andReturn([
            (object) ['id' => '101', 'extensionNumber' => '101', 'contact' => (object) ['email' => $employe->email]],
        ]);
        $mock->shouldReceive('getCallHistoryByUser')
            ->once()
            ->with('101', Mockery::type('array'))
            ->andReturn([['id' => 'c1', 'direction' => 'Outbound', 'duration' => 45]]);

        $this->postJson("/api/v1/call-logs/employees/{$employe->id}/logs/sync")
            ->assertOk()
            ->assertJsonPath('data.sync.extension_id', '101')
            ->assertJsonPath('data.sync.resolved_by', 'email')
            ->assertJsonPath('data.records.0.id', 'c1')
            ->assertJsonPath('data.records.0.duration', 45);
    }

    public function test_sync_returns_a_clear_422_when_no_extension_matches(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe('dev-inconnu');

        $mock = $this->mockService();
        $mock->shouldReceive('getDevices')->once()->andReturn([]);
        $mock->shouldReceive('getAllUsers')->once()->andReturn([]);
        $mock->shouldNotReceive('getCallHistoryByUser');

        $this->postJson("/api/v1/call-logs/employees/{$employe->id}/logs/sync")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'error',
                'Aucune extension RingCentral rattachée à cet employé (appareil « dev-inconnu » introuvable, e-mail « '
                    .$employe->email
                    .' » sans correspondance). Choisissez son appareil source dans sa fiche ou lancez la synchronisation des employés.'
            );
    }

    public function test_ringcentral_failure_returns_502(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe();

        $mock = $this->mockService();
        $mock->shouldReceive('getDevices')->once()->andThrow(new Exception('RingCentral non configuré'));

        $this->postJson("/api/v1/call-logs/employees/{$employe->id}/logs/sync")
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error']);
    }

    // ── Proxy d'enregistrement ──────────────────────────────────────────

    public function test_recording_content_is_proxied_with_its_mime_type(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $mock = $this->mockService();
        $mock->shouldReceive('getRecordingContent')
            ->once()
            ->with('REC-123')
            ->andReturn(['content_type' => 'audio/mpeg', 'body' => 'AUDIODATA']);

        $this->get('/api/v1/call-logs/recordings/REC-123/content')
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertSee('AUDIODATA', false);
    }

    public function test_recording_content_failure_returns_502(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));

        $mock = $this->mockService();
        $mock->shouldReceive('getRecordingContent')->once()->andThrow(new Exception('contentUri absent'));

        $this->getJson('/api/v1/call-logs/recordings/REC-123/content')
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }

    public function test_invalid_recording_id_is_rejected_before_any_api_call(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $mock = $this->mockService();
        $mock->shouldNotReceive('getRecordingContent');

        $this->getJson('/api/v1/call-logs/recordings/rec%20ording/content')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recordingId']);
    }
}
