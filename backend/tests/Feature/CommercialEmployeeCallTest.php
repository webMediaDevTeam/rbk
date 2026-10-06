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
 * Appel sortant de l'employé (bouton « Appeler » des pages Mes listes,
 * Rappels et BV) : `POST /call-logs/my-call` — le navigateur n'envoie que
 * la destination, la source est **résolue côté API** dans
 * `employees.ringcentral_from_number`, et `record: true` (défaut)
 * démarre l'enregistrement de l'appel (docs/TODOS.md « Appel direct »).
 */
class CommercialEmployeeCallTest extends TestCase
{
    use RefreshDatabase;

    /** Service facturé branché sur le conteneur (aucun appel réseau réel). */
    private function mockService(): RingCentralService
    {
        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);

        return $mock;
    }

    /** Employé (COMERCIAL) + son `employees` 1:1 (source configurée ou non). */
    private function employe(?string $fromNumber, ?string $deviceId = null): User
    {
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);

        $user->employee()->create([
            'first_name'              => 'Jean',
            'last_name'               => 'Tremblay',
            'ringcentral_from_number' => $fromNumber,
            'ringcentral_device_id'   => $deviceId,
        ]);

        return $user;
    }

    public function test_commercial_calls_with_his_own_from_number(): void
    {
        $employe = $this->employe('+15146120498', '35664208024');
        Sanctum::actingAs($employe);

        $mock = $this->mockService();
        // La source vient de la fiche employé, jamais du navigateur.
        $mock->shouldReceive('makeCallOut')
            ->once()
            ->with('15145550123', '+15146120498', '35664208024', null)
            ->andReturn(['session' => ['id' => 'sess-42', 'parties' => [['id' => 'party-1']]]]);
        // `record: true` (défaut) → l'enregistrement est démarré aussitôt.
        $mock->shouldReceive('startRecording')->once()->with('sess-42', 'party-1')->andReturn(['id' => 'rec-1']);

        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', 'sess-42')
            ->assertJsonPath('data.party_id', 'party-1')
            ->assertJsonPath('data.from', '+15146120498')
            ->assertJsonPath('data.to', '15145550123')
            ->assertJsonPath('data.record', true)
            ->assertJsonPath('data.recorded', true);
    }

    public function test_recording_can_be_disabled_with_record_false(): void
    {
        Sanctum::actingAs($this->employe('+15146120498'));

        $mock = $this->mockService();
        $mock->shouldReceive('makeCallOut')->once()->andReturn([
            'uri' => 'https://platform.ringcentral.com/restapi/v1.0/account/1/telephony/session/sess-42',
            'parties' => [['id' => 'party-1']],
        ]);
        $mock->shouldNotReceive('startRecording');

        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123', 'record' => false])
            ->assertOk()
            ->assertJsonPath('data.record', false)
            ->assertJsonPath('data.recorded', false);
    }

    public function test_recording_failure_does_not_fail_the_call(): void
    {
        Sanctum::actingAs($this->employe('+15146120498'));

        $mock = $this->mockService();
        $mock->shouldReceive('makeCallOut')->once()->andReturn([
            'uri' => 'https://platform.ringcentral.com/restapi/v1.0/account/1/telephony/session/sess-42',
            'parties' => [['id' => 'party-1']],
        ]);
        // Partie encore en « Setup » : l'appel doit rester 200, le client
        // retentera `…/record`.
        $mock->shouldReceive('startRecording')->once()->andThrow(new Exception('Party is not connected'));

        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123'])
            ->assertOk()
            ->assertJsonPath('data.record', true)
            ->assertJsonPath('data.recorded', false);
    }

    public function test_commercial_can_start_a_recording_of_a_session(): void
    {
        Sanctum::actingAs($this->employe('+15146120498'));

        $mock = $this->mockService();
        $mock->shouldReceive('startRecording')->once()->with('sess-42', 'party-1')->andReturn(['id' => 'rec-1']);

        $this->postJson('/api/v1/call-logs/calls/sess-42/parties/party-1/record')
            ->assertOk()
            ->assertJsonPath('data.session_id', 'sess-42')
            ->assertJsonPath('data.party_id', 'party-1');
    }

    public function test_commercial_without_a_from_number_gets_a_clear_422(): void
    {
        Sanctum::actingAs($this->employe(null));

        $mock = $this->mockService();
        $mock->shouldNotReceive('makeCallOut');

        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', "Aucun numéro source configuré : demandez à un administrateur de choisir votre appareil / numéro (fiche employé) avant d'appeler.");
    }

    public function test_destination_is_required(): void
    {
        Sanctum::actingAs($this->employe('+15146120498'));

        $mock = $this->mockService();
        $mock->shouldNotReceive('makeCallOut');

        $this->postJson('/api/v1/call-logs/my-call', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_only_commercial_can_use_the_employee_call_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123'])->assertStatus(403);

        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));
        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123'])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_call(): void
    {
        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123'])->assertUnauthorized();
    }

    public function test_commercial_cannot_use_the_super_admin_call_endpoint(): void
    {
        Sanctum::actingAs($this->employe('+15146120498'));

        $this->postJson('/api/v1/call-logs/call', ['to' => '15145550123'])->assertStatus(403);
    }

    public function test_ringcentral_failure_returns_502(): void
    {
        Sanctum::actingAs($this->employe('+15146120498'));

        $mock = $this->mockService();
        $mock->shouldReceive('makeCallOut')->once()->andThrow(new \Exception('RingCentral non configuré'));

        $this->postJson('/api/v1/call-logs/my-call', ['to' => '15145550123'])
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error']);
    }
}
