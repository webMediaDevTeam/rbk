<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\CallRecording;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\User;
use App\Services\RingCentralService;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EmployerClientCallTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployeeWithClient(): array
    {
        $enterprise = Enterprise::forceCreate(['name' => 'Call Test Enterprise']);
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        $employee = $user->employee()->create([
            'enterprise_id' => $enterprise->id,
            'first_name' => 'Call',
            'last_name' => 'Owner',
            'ringcentral_extension_id' => 'rc-user-1',
            'ringcentral_device_id' => 'rc-device-1',
            'ringcentral_from_number' => '+15145550199',
        ]);
        $client = Client::create([
            'name' => 'Target Client',
            'phone' => '514-555-0100',
            'status' => Client::STATUS_RESERVED,
        ]);
        Reservation::create([
            'client_id' => $client->id,
            'comercial_id' => $user->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        return [$user, $employee, $client];
    }

    private function bindRingCentralMock(): MockInterface
    {
        $mock = Mockery::mock(RingCentralService::class);
        $mock->shouldReceive('makeCallOut')
            ->once()
            ->with('+1 (514) 555-0100', '+15145550199', 'rc-device-1', null)
            ->andReturn(['session' => ['id' => 'rc-session-1', 'parties' => [['id' => 'rc-party-1']]]]);
        $mock->shouldReceive('startRecording')->once()->andThrow(new Exception('Not connected yet'));
        $this->app->instance(RingCentralService::class, $mock);

        return $mock;
    }

    public function test_employee_can_call_owned_client_and_call_note_are_linked(): void
    {
        [$user, , $client] = $this->makeEmployeeWithClient();
        Sanctum::actingAs($user);
        $this->bindRingCentralMock();

        $response = $this->postJson('/api/v1/call-logs/my-call', [
            'client_id' => $client->id,
            'to' => '+1 (514) 555-0100',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.session_id', 'rc-session-1')
            ->assertJsonPath('data.to', '+1 (514) 555-0100');

        $this->assertDatabaseHas('call_logs', [
            'ringcentral_session_id' => 'rc-session-1',
            'client_id' => $client->id,
        ]);
        $callId = $response->json('data.call_log_id');
        $this->assertNotEmpty($callId);
        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'call_log_id' => $callId,
            'sender_id' => Note::SENDER_SYSTEM,
            'type' => Note::TYPE_NOTE,
        ]);

        $this->getJson("/api/v1/clients/{$client->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.call_log.id', $callId);
    }

    public function test_employee_cannot_call_a_client_reserved_by_another_employee(): void
    {
        [$owner, , $client] = $this->makeEmployeeWithClient();
        $other = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Reservation::where('client_id', $client->id)->update(['comercial_id' => $owner->id]);
        Sanctum::actingAs($other);

        $this->postJson('/api/v1/call-logs/my-call', [
            'client_id' => $client->id,
            'to' => '+15145550100',
        ])->assertNotFound();

        $this->getJson("/api/v1/clients/{$client->id}/notes")->assertNotFound();
        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client.notes', []);
    }

    /**
     * « Voir l'appel » de la fiche client : journal + enregistrements
     * réservés ADMIN / SUPER_ADMIN — un COMERCIAL n'y a plus accès, même
     * propriétaire de l'appel, même avec le privilège de libération (§7.1).
     */
    public function test_client_call_details_and_recordings_are_reserved_to_admins(): void
    {
        [$owner, $employee, $client] = $this->makeEmployeeWithClient();
        $call = CallLog::create([
            'employee_id' => $employee->id,
            'client_id' => $client->id,
            'ringcentral_call_id' => 'rc-call-1',
            'ringcentral_session_id' => 'rc-session-details',
            'ringcentral_extension_id' => 'rc-user-1',
            'direction' => 'Outbound',
            'to_number' => '+15145550100',
            'started_at' => now(),
            'result' => 'Disconnected',
        ]);
        CallRecording::create([
            'call_log_id' => $call->id,
            'ringcentral_recording_id' => 'rc-recording-1',
        ]);

        $mock = Mockery::mock(RingCentralService::class);
        // Un enregistrement est déjà rangé : seuls l'historique détaillé et
        // la session restent à rapatrier (aucune recherche par cible).
        $mock->shouldReceive('findCallLogByTarget')->never();
        $mock->shouldReceive('getCallHistoryByUser')
            ->once()
            ->with('rc-user-1', ['view' => 'Detailed', 'perPage' => 100])
            ->andReturn([
                [
                    'id' => 'rc-call-1',
                    'sessionId' => 'rc-session-details',
                    'startTime' => '2026-10-07T12:00:00Z',
                    'result' => 'Disconnected',
                    'duration' => 38,
                    'events' => [['time' => '2026-10-07T12:00:00Z', 'type' => 'Call started']],
                ],
            ]);
        $mock->shouldReceive('getCallSession')
            ->once()
            ->with('rc-session-details')
            ->andReturn(['session' => ['id' => 'rc-session-details', 'status' => ['code' => 'Disconnected']]]);
        $mock->shouldReceive('getRecordingContent')
            ->once()
            ->with('rc-recording-1')
            ->andReturn(['content_type' => 'audio/mpeg', 'body' => 'audio-bytes']);
        $this->app->instance(RingCentralService::class, $mock);

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}")
            ->assertOk()
            ->assertJsonPath('data.client.name', 'Target Client')
            ->assertJsonPath('data.ringcentral_call_id', 'rc-call-1')
            ->assertJsonPath('data.duration', 38)
            ->assertJsonPath('data.events.0.type', 'Call started')
            ->assertJsonPath('data.recordings.0.id', 'rc-recording-1');

        $this->get("/api/v1/call-logs/client-calls/{$call->id}/recordings/rc-recording-1/content")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertContent('audio-bytes');

        // Propriétaire de l'appel : 403 — plus d'exception « propriétaire ».
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}")->assertForbidden();
        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}/recordings/rc-recording-1/content")
            ->assertForbidden();

        // Même avec le privilège de libération : l'écoute reste fermée.
        $owner->forceFill(['has_permission' => true])->save();
        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}")->assertForbidden();
        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}/recordings/rc-recording-1/content")
            ->assertForbidden();

        // Un autre commercial : 403 également.
        $other = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}")->assertForbidden();
        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}/recordings/rc-recording-1/content")
            ->assertForbidden();
    }

    /**
     * Appel ouvert « à chaud » (aucun enregistrement local) : les détails
     * vont chercher la ligne RingCentral de la **session**, qui porte
     * l'enregistrement automatique (console admin → Call Recording) — la
     * ligne est complétée (id de call log, durée, résultat) et l'audio est
     * rangé dans `call_recordings`, lisible aussitôt par la modale.
     */
    public function test_call_details_fetch_the_automatic_recording_by_session_id(): void
    {
        [, $employee, $client] = $this->makeEmployeeWithClient();
        $call = CallLog::create([
            'employee_id' => $employee->id,
            'client_id' => $client->id,
            'ringcentral_session_id' => 's-hot-1',
            'ringcentral_extension_id' => 'rc-user-1',
            'direction' => 'Outbound',
            'to_number' => '+15145550100',
            'started_at' => now(),
            'result' => 'Setup',
        ]);
        // La consultation est réservée aux gestionnaires (ADMIN).
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $mock = Mockery::mock(RingCentralService::class);
        $mock->shouldReceive('findCallLogByTarget')
            ->once()
            ->with('+15145550100', Mockery::type(CarbonInterface::class))
            ->andReturn([
                'id' => 'rc-call-9',
                'sessionId' => '675097585025',
                'startTime' => '2026-10-08T08:13:45Z',
                'endTime' => '2026-10-08T08:15:00Z',
                'result' => 'Call connected',
                'duration' => 75,
                'direction' => 'Outbound',
                'from' => ['phoneNumber' => '+15146005994'],
                'to' => ['phoneNumber' => '+15145550100'],
                'recording' => [
                    'id' => 'rc-rec-9',
                    'type' => 'Automatic',
                    'contentUri' => 'https://media.ringcentral.com/restapi/v1.0/account/1/recording/rc-rec-9/content',
                ],
            ]);
        $mock->shouldReceive('getCallSession')
            ->once()
            ->with('s-hot-1')
            ->andReturn(['session' => ['id' => 's-hot-1', 'status' => ['code' => 'Disconnected']]]);
        $this->app->instance(RingCentralService::class, $mock);

        $this->getJson("/api/v1/call-logs/client-calls/{$call->id}")
            ->assertOk()
            ->assertJsonPath('data.ringcentral_call_id', 'rc-call-9')
            ->assertJsonPath('data.session_id', 's-hot-1')
            ->assertJsonPath('data.status', 'Call connected')
            ->assertJsonPath('data.duration', 75)
            ->assertJsonPath('data.recordings.0.id', 'rc-rec-9')
            ->assertJsonPath('data.recordings.0.type', 'Automatic');

        $this->assertDatabaseHas('call_recordings', [
            'call_log_id' => $call->id,
            'ringcentral_recording_id' => 'rc-rec-9',
            'type' => 'Automatic',
        ]);
    }
}
