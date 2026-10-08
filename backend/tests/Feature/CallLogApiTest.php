<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RingCentralService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CallLogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']);
    }

    public function test_unauthenticated_cannot_access_call_logs(): void
    {
        $this->getJson('/api/v1/call-logs/users')->assertUnauthorized();
        $this->getJson('/api/v1/call-logs/users/123')->assertUnauthorized();
        $this->getJson('/api/v1/call-logs/by-phone/14155552671')->assertUnauthorized();
    }

    public function test_users_list_returns_502_when_ringcentral_throws(): void
    {
        Sanctum::actingAs($this->user);

        $mock = Mockery::mock(RingCentralService::class);
        $mock->shouldReceive('getAllUsers')->once()->andThrow(new Exception('Service down'));
        $this->app->instance(RingCentralService::class, $mock);

        $response = $this->getJson('/api/v1/call-logs/users');

        $response->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'error']);
    }

    public function test_users_list_returns_data_on_success(): void
    {
        Sanctum::actingAs($this->user);

        $mock = Mockery::mock(RingCentralService::class);
        $mock->shouldReceive('getAllUsers')->once()->andReturn([
            ['id' => '101', 'name' => 'John Doe', 'extensionNumber' => '101'],
        ]);
        $this->app->instance(RingCentralService::class, $mock);

        $response = $this->getJson('/api/v1/call-logs/users');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'John Doe');
    }

    public function test_user_calls_returns_data(): void
    {
        Sanctum::actingAs($this->user);

        $mock = Mockery::mock(RingCentralService::class);
        $mock->shouldReceive('getCallHistoryByUser')
            ->with('101', Mockery::type('array'))
            ->once()
            ->andReturn([
                ['id' => 'call_1', 'direction' => 'Outbound', 'duration' => 45],
            ]);
        $this->app->instance(RingCentralService::class, $mock);

        $response = $this->getJson('/api/v1/call-logs/users/101');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'call_1');
    }

    public function test_calls_to_number_returns_data(): void
    {
        Sanctum::actingAs($this->user);

        $mock = Mockery::mock(RingCentralService::class);
        $mock->shouldReceive('getCallHistoryToNumber')
            ->with('14155552671', '~')
            ->once()
            ->andReturn([
                ['id' => 'call_2', 'to' => ['phoneNumber' => '14155552671']],
            ]);
        $this->app->instance(RingCentralService::class, $mock);

        $response = $this->getJson('/api/v1/call-logs/by-phone/14155552671');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'call_2');
    }

    public function test_commercial_cannot_read_arbitrary_ringcentral_call_history(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/call-logs/users/101')->assertForbidden();
        $this->getJson('/api/v1/call-logs/by-phone/14155552671')->assertForbidden();
    }
}
