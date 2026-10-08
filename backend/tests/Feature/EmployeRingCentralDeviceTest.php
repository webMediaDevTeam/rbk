<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\RingCentralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Source d'appel RingCentral de l'employé (COMERCIAL) : à la création comme
 * à la mise à jour (Admin / Super Admin), le select d'appareils enregistre
 * **l'id de l'appareil + le numéro « from »** sur la table `employees` —
 * docs/TODOS.md « Source d'appel de l'employé ».
 */
class EmployeRingCentralDeviceTest extends TestCase
{
    use RefreshDatabase;

    /** Employé (COMERCIAL) + son `employees` 1:1. */
    private function employe(array $userAttributes = []): User
    {
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE'] + $userAttributes);

        $user->employee()->create([
            'first_name' => 'Jean',
            'last_name' => 'Tremblay',
        ]);

        return $user->fresh('employee');
    }

    public function test_admin_stores_device_and_from_number_when_creating_an_employe(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));
        $enterprise = Enterprise::forceCreate([
            'name' => 'RingCentral Test Enterprise',
        ]);

        $this->postJson('/api/v1/users', [
            'email' => 'nouveau@employe.local',
            'role' => 'COMERCIAL',
            'first_name' => 'Nouvel',
            'last_name' => 'Employe',
            'mot_de_passe' => 'secret-pass',
            'mot_de_passe_confirmation' => 'secret-pass',
            'enterprise_id' => $enterprise->id,
            'ringcentral_extension_id' => '35664208024',
            'ringcentral_device_id' => '35664208024',
            'ringcentral_from_number' => '+15146120498',
        ])
            ->assertCreated()
            ->assertJsonPath('data.utilisateur.profil.ringcentral_extension_id', '35664208024')
            ->assertJsonPath('data.utilisateur.profil.ringcentral_device_id', '35664208024')
            ->assertJsonPath('data.utilisateur.profil.ringcentral_from_number', '+15146120498');

        $this->assertDatabaseHas('employees', [
            'user_id' => User::where('email', 'nouveau@employe.local')->value('id'),
            'ringcentral_device_id' => '35664208024',
            'ringcentral_from_number' => '+15146120498',
        ]);
    }

    public function test_super_admin_updates_then_clears_the_device_of_an_employe(): void
    {
        $employe = $this->employe();
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));

        // 1. Enregistrement d'un appareil + numéro.
        $this->putJson("/api/v1/users/{$employe->id}", [
            'ringcentral_extension_id' => 'extension-22',
            'ringcentral_device_id' => '35682803024',
            'ringcentral_from_number' => '+16473603035',
        ])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.profil.ringcentral_extension_id', 'extension-22')
            ->assertJsonPath('data.utilisateur.profil.ringcentral_device_id', '35682803024')
            ->assertJsonPath('data.utilisateur.profil.ringcentral_from_number', '+16473603035');

        $this->assertDatabaseHas('employees', [
            'user_id' => $employe->id,
            'ringcentral_device_id' => '35682803024',
            'ringcentral_from_number' => '+16473603035',
        ]);

        // 2. Retrait de la source (`null` explicite = colonnes vidées).
        $this->putJson("/api/v1/users/{$employe->id}", [
            'ringcentral_extension_id' => null,
            'ringcentral_device_id' => null,
            'ringcentral_from_number' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.profil.ringcentral_device_id', null);

        $this->assertDatabaseHas('employees', [
            'user_id' => $employe->id,
            'ringcentral_device_id' => null,
            'ringcentral_from_number' => null,
        ]);
    }

    public function test_device_fields_are_validated(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));
        $enterprise = Enterprise::forceCreate(['name' => 'Device Validation Enterprise']);

        $this->postJson('/api/v1/users', [
            'email' => 'trop@long.local',
            'role' => 'COMERCIAL',
            'first_name' => 'Trop',
            'last_name' => 'Long',
            'mot_de_passe' => 'secret-pass',
            'mot_de_passe_confirmation' => 'secret-pass',
            'enterprise_id' => $enterprise->id,
            'ringcentral_device_id' => str_repeat('a', 65),
            'ringcentral_from_number' => '+15145550123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ringcentral_device_id']);
    }

    /** Le select d'appareils est ADMIN + SUPER_ADMIN ; un employé reste exclu. */
    public function test_devices_endpoint_is_restricted_to_admin_and_super_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']));
        $this->getJson('/api/v1/call-logs/devices')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('getDevices')->once()->with(100)->andReturn([
            ['id' => 'dev-1', 'name' => 'Bureau', 'extension' => ['id' => 'ext-101']],
        ]);
        $mock->shouldReceive('getPhoneNumbers')->once()->andReturn([
            ['phoneNumber' => '+15145550100', 'extension' => ['id' => 'ext-101']],
        ]);

        $this->getJson('/api/v1/call-logs/devices')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 'dev-1')
            ->assertJsonPath('data.0.phoneNumber', '+15145550100');
    }

    /** Les autres routes RingCentral (contrôle d'appel) restent SUPER_ADMIN. */
    public function test_admin_cannot_use_the_call_control_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $mock = Mockery::mock(RingCentralService::class);
        $this->app->instance(RingCentralService::class, $mock);
        $mock->shouldReceive('makeCallOut')->never();

        $this->postJson('/api/v1/call-logs/call', ['to' => '15145550123'])->assertForbidden();
        $this->getJson('/api/v1/call-logs/account')->assertForbidden();
    }

    /** Panne RingCentral : la création d'un employé ne doit pas échouer. */
    public function test_missing_device_fields_do_not_break_creation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));
        $enterprise = Enterprise::forceCreate(['name' => 'No Device Test Enterprise']);

        $this->postJson('/api/v1/users', [
            'email' => 'sans@source.local',
            'role' => 'COMERCIAL',
            'first_name' => 'Sans',
            'last_name' => 'Source',
            'mot_de_passe' => 'secret-pass',
            'mot_de_passe_confirmation' => 'secret-pass',
            'enterprise_id' => $enterprise->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.utilisateur.profil.ringcentral_device_id', null);

        $this->assertDatabaseHas('employees', [
            'user_id' => User::where('email', 'sans@source.local')->value('id'),
            'ringcentral_device_id' => null,
            'ringcentral_from_number' => null,
        ]);
    }
}
