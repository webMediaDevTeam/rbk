<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RingCentralService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Onglet « Appels » de la fiche employé (`/comercialDetail/:id`,
 * docs/TODOS.md « Onglet Appels ») :
 *
 *   GET /call-logs/employees/{id}/logs   — journal RingCentral résolu **via
 *                                          l'appareil de l'employé** ;
 *   GET /call-logs/recordings/{id}/content — proxy audio (le `contentUri`
 *                                          exige `Authorization`).
 *
 * Les deux sont réservés ADMIN + SUPER_ADMIN.
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
            'first_name'              => 'Jean',
            'last_name'               => 'Tremblay',
            'ringcentral_device_id'   => $deviceId,
            'ringcentral_from_number' => '+15146120498',
        ]);

        return $user;
    }

    public function test_unauthenticated_cannot_read_logs_or_recordings(): void
    {
        $this->getJson('/api/v1/call-logs/employees/1/logs')->assertUnauthorized();
        $this->getJson('/api/v1/call-logs/recordings/REC-1/content')->assertUnauthorized();
    }

    public function test_commercial_cannot_read_the_call_journal_of_an_employee(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/call-logs/employees/1/logs')->assertForbidden();
        $this->getJson('/api/v1/call-logs/recordings/REC-1/content')->assertForbidden();
    }

    public function test_admin_resolves_the_extension_via_the_device_id(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe('dev-9');

        $mock = $this->mockService();
        // 1. L'appareil de la fiche donne l'extension (demandé « via le
        //    device id ») : aucune recherche par e-mail.
        $mock->shouldReceive('getDevices')->once()->with(250)->andReturn([
            ['id' => 'dev-9', 'extensionNumber' => '101', 'extension' => ['id' => 'ext-101']],
        ]);
        $mock->shouldNotReceive('getAllUsers');
        // 2. Journal de cette extension ; seuls les appels portant le bon
        //    `deviceId` sont gardés.
        $mock->shouldReceive('getCallHistoryByUser')
            ->once()
            ->with('ext-101', Mockery::type('array'))
            ->andReturn([
                ['id' => 'c1', 'direction' => 'Outbound', 'duration' => 45, 'deviceId' => 'dev-9',
                    'recording' => ['id' => 'REC-1']],
                ['id' => 'c2', 'direction' => 'Inbound', 'duration' => 12, 'deviceId' => 'autre-dev'],
            ]);

        $this->getJson("/api/v1/call-logs/employees/{$employe->id}/logs")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.extension_id', 'ext-101')
            ->assertJsonPath('data.extension_number', '101')
            ->assertJsonPath('data.resolved_by', 'device')
            ->assertJsonPath('data.device_id', 'dev-9')
            ->assertJsonPath('data.from_number', '+15146120498')
            ->assertJsonPath('data.filtered_by_device', true)
            ->assertJsonPath('data.records.0.id', 'c1')
            ->assertJsonPath('data.records.0.recording.id', 'REC-1')
            ->assertJsonMissingPath('data.records.1');
    }

    public function test_falls_back_to_the_email_when_the_device_is_unknown(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe('dev-inconnu');

        $mock = $this->mockService();
        $mock->shouldReceive('getDevices')->once()->with(250)->andReturn([
            ['id' => 'autre-dev', 'extensionNumber' => '200', 'extension' => ['id' => 'ext-200']],
        ]);
        $mock->shouldReceive('getAllUsers')->once()->with(250)->andReturn([
            (object) ['id' => '101', 'extensionNumber' => '101', 'contact' => (object) ['email' => $employe->email]],
        ]);
        $mock->shouldReceive('getCallHistoryByUser')
            ->once()
            ->with('101', Mockery::type('array'))
            ->andReturn([['id' => 'c1', 'direction' => 'Outbound', 'duration' => 45]]);

        $this->getJson("/api/v1/call-logs/employees/{$employe->id}/logs")
            ->assertOk()
            ->assertJsonPath('data.extension_id', '101')
            ->assertJsonPath('data.resolved_by', 'email')
            // Aucun `deviceId` dans les journaux → rien n'est filtré (sinon
            // le tableau serait vide à tort).
            ->assertJsonPath('data.filtered_by_device', false)
            ->assertJsonPath('data.records.0.id', 'c1');
    }

    public function test_returns_a_clear_422_when_no_extension_matches(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe('dev-inconnu');

        $mock = $this->mockService();
        $mock->shouldReceive('getDevices')->once()->andReturn([]);
        $mock->shouldReceive('getAllUsers')->once()->andReturn([]);
        $mock->shouldNotReceive('getCallHistoryByUser');

        $this->getJson("/api/v1/call-logs/employees/{$employe->id}/logs")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Aucune extension RingCentral rattachée à cet employé (appareil « dev-inconnu » introuvable, e-mail « '.$employe->email.' » sans correspondance). Choisissez son appareil source dans sa fiche.');
    }

    public function test_user_without_an_employee_file_gets_a_422(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        Sanctum::actingAs($admin);

        $mock = $this->mockService();
        $mock->shouldNotReceive('getDevices');

        $this->getJson("/api/v1/call-logs/employees/{$admin->id}/logs")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Aucune fiche employé rattachée à cet utilisateur.');
    }

    public function test_ringcentral_failure_returns_502(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $employe = $this->employe();

        $mock = $this->mockService();
        $mock->shouldReceive('getDevices')->once()->andThrow(new Exception('RingCentral non configuré'));

        $this->getJson("/api/v1/call-logs/employees/{$employe->id}/logs")
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
