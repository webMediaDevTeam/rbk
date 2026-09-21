<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseCrudAndCommercialLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_update_and_delete_enterprise_without_creating_user(): void
    {
        $admin = User::factory()->create([
            'role' => 'ADMIN',
        ]);

        Sanctum::actingAs($admin);

        // 1. Create enterprise
        $createResponse = $this->postJson('/api/v1/enterprises', [
            'name' => 'Acme Corporation',
            'email' => 'contact@acme.local',
            'phone' => '514-555-9999',
            'tax_number' => 'TAX-9999',
            'address' => '100 Main Street',
        ]);

        $createResponse->assertCreated();
        $enterpriseId = $createResponse->json('data.entreprise.id');
        $this->assertNotEmpty($enterpriseId);

        // Ensure NO user was created for this enterprise
        $this->assertDatabaseMissing('users', [
            'email' => 'contact@acme.local',
        ]);

        // Ensure enterprise exists in enterprises table
        $this->assertDatabaseHas('enterprises', [
            'id' => $enterpriseId,
            'name' => 'Acme Corporation',
            'email' => 'contact@acme.local',
        ]);

        // 2. List enterprises
        $listResponse = $this->getJson('/api/v1/enterprises?search=Acme');
        $listResponse->assertOk()
            ->assertJsonPath('data.entreprises.0.id', $enterpriseId)
            ->assertJsonPath('data.entreprises.0.name', 'Acme Corporation');

        // 3. Update enterprise
        $updateResponse = $this->putJson("/api/v1/enterprises/{$enterpriseId}", [
            'name' => 'Acme Inc.',
            'phone' => '514-555-8888',
        ]);
        $updateResponse->assertOk()
            ->assertJsonPath('data.entreprise.name', 'Acme Inc.')
            ->assertJsonPath('data.entreprise.phone', '514-555-8888');

        // 4. Toggle status
        $toggleResponse = $this->patchJson("/api/v1/enterprises/{$enterpriseId}/status", [
            'status' => 'INACTIVE',
        ]);
        $toggleResponse->assertOk()
            ->assertJsonPath('data.entreprise.status', 'INACTIVE');

        // 5. Create commercial linked to this enterprise
        $createCommercialResponse = $this->postJson('/api/v1/users', [
            'email' => 'commercial@acme.local',
            'role' => 'COMERCIAL',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '514-555-1111',
            'enterprise_id' => $enterpriseId,
        ]);

        $createCommercialResponse->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'commercial@acme.local',
            'role' => 'COMERCIAL',
        ]);
        $this->assertDatabaseHas('employees', [
            'enterprise_id' => $enterpriseId,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertEquals('Acme Inc.', $createCommercialResponse->json('data.utilisateur.profil.entreprise_name'));
        $this->assertEquals($enterpriseId, $createCommercialResponse->json('data.utilisateur.profil.entreprise_id'));

        // 6. Delete enterprise
        $deleteResponse = $this->deleteJson("/api/v1/enterprises/{$enterpriseId}");
        $deleteResponse->assertOk();
        $this->assertDatabaseMissing('enterprises', ['id' => $enterpriseId]);
    }

    public function test_commercial_cannot_access_enterprises_endpoints(): void
    {
        $commercial = User::factory()->create([
            'role' => 'COMERCIAL',
        ]);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/enterprises')->assertForbidden();
        $this->postJson('/api/v1/enterprises', ['name' => 'Test'])->assertForbidden();
    }
}
