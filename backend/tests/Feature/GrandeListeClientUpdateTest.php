<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mise à jour **complète** d'une fiche client depuis la Grande liste admin
 * (`PUT /commercials/clients/{id}`) + exposition des champs éditables dans le
 * corps de `GET /commercials/clients`.
 */
class GrandeListeClientUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Ancien nom',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    public function test_admin_can_update_every_exposed_field(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson("/api/v1/commercials/clients/{$client->id}", [
            'name' => 'Nouveau nom',
            'enterprise_name' => 'Bâtiment X',
            'email' => 'contact@exemple.com',
            'phone' => '4381234567',
            'municipality' => 'Laval',
            'administrative_region' => 'Laurentides',
            'full_address' => '1 rue Tremble',
            'licence_number' => 'RBQ-42',
            'licence_end_date' => '2030-01-31',
            'neq' => '1234567890',
            'representative_name' => 'Jean',
            'intervenant_name' => 'Paul',
            'source' => 'Angalis',
        ])->assertOk()->assertJsonPath('success', true);

        $client->refresh();

        $this->assertSame('Nouveau nom', $client->name);
        $this->assertSame('Bâtiment X', $client->enterprise_name);
        $this->assertSame('contact@exemple.com', $client->email);
        // Le numéro est normalisé par `Client::cleanPhone()` (même format que
        // la saisie dédiée).
        $this->assertSame('438-123-4567', $client->phone);
        $this->assertSame('Laval', $client->municipality);
        $this->assertSame('Laurentides', $client->administrative_region);
        $this->assertSame('RBQ-42', $client->licence_number);
        $this->assertSame('Angalis', $client->source);
        // Fiche modifiée à la main → l'import ne la réécrira plus.
        $this->assertTrue($client->is_manually_updated);
    }

    public function test_update_only_touches_the_fields_that_are_sent(): void
    {
        $client = $this->makeClient(['municipality' => 'Québec', 'status' => Client::STATUS_AVAILABLE]);
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson("/api/v1/commercials/clients/{$client->id}", ['name' => 'Renommé'])
            ->assertOk();

        $client->refresh();
        $this->assertSame('Renommé', $client->name);
        // Non envoyé → intact.
        $this->assertSame('Québec', $client->municipality);
        $this->assertSame(Client::STATUS_AVAILABLE, $client->status);
    }

    public function test_empty_phone_removes_the_number(): void
    {
        $client = $this->makeClient(['phone' => '514-555-0100']);
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson("/api/v1/commercials/clients/{$client->id}", ['phone' => ''])
            ->assertOk();

        $this->assertNull($client->refresh()->phone);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson("/api/v1/commercials/clients/{$client->id}", ['email' => 'pas-un-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_commercial_cannot_update_a_client(): void
    {
        $client = $this->makeClient();
        $commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Sanctum::actingAs($commercial);

        $this->putJson("/api/v1/commercials/clients/{$client->id}", ['name' => 'X'])
            ->assertForbidden();

        $this->assertSame('Ancien nom', $client->refresh()->name);
    }

    public function test_grande_liste_exposes_editable_fields(): void
    {
        $client = $this->makeClient([
            'administrative_region' => 'Outaouais',
            'full_address' => '9 avenue des Champs',
            'representative_name' => 'Marie',
            'intervenant_name' => 'Luc',
        ]);
        Sanctum::actingAs($this->makeAdmin());

        $row = $this->getJson('/api/v1/commercials/clients')->assertOk()
            ->json('data.clients.0');

        $this->assertSame($client->id, $row['id']);
        $this->assertSame('514-555-0100', $row['phone']);
        $this->assertSame('Outaouais', $row['administrative_region']);
        $this->assertSame('9 avenue des Champs', $row['full_address']);
        $this->assertSame('Marie', $row['representative_name']);
        $this->assertSame('Luc', $row['intervenant_name']);
    }

    public function test_update_logs_a_mise_a_jour_note_detailing_the_french_diff(): void
    {
        $client = $this->makeClient();
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/commercials/clients/{$client->id}", [
            'name' => 'Nouveau nom',
            'phone' => '',
            'municipality' => 'Laval',
        ])->assertOk();

        // Une seule note « Mise à jour », émetteur = l'éditeur, description
        // champ par champ : ancien → nouveau valeur, `(vide)` pour le vide.
        $note = Note::where('client_id', $client->id)->where('type', Note::TYPE_MISE_A_JOUR)->sole();
        $this->assertSame($admin->id, $note->sender_id);
        $this->assertStringContainsString('Nom : « Ancien nom » → « Nouveau nom »', $note->description);
        $this->assertStringContainsString('Téléphone : « 514-555-0100 » → « (vide) »', $note->description);
        $this->assertStringContainsString('Municipalité : « (vide) » → « Laval »', $note->description);
        // Champs non envoyés → pas dans le diff.
        $this->assertStringNotContainsString('Source', $note->description);
    }

    public function test_no_mise_a_jour_note_when_the_request_changes_nothing(): void
    {
        $client = $this->makeClient(['is_manually_updated' => true]);
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson("/api/v1/commercials/clients/{$client->id}", ['name' => 'Ancien nom'])
            ->assertOk();

        $this->assertSame(0, Note::where('client_id', $client->id)
            ->where('type', Note::TYPE_MISE_A_JOUR)->count());
    }

    public function test_phone_saisie_logs_its_own_mise_a_jour_note(): void
    {
        $client = $this->makeClient(['phone' => null]);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/commercials/clients/{$client->id}/phone", ['phone' => '5143535820'])
            ->assertOk();

        $note = Note::where('client_id', $client->id)->where('type', Note::TYPE_MISE_A_JOUR)->sole();
        $this->assertSame($admin->id, $note->sender_id);
        $this->assertStringContainsString('Téléphone : « (vide) » → « 514-353-5820 »', $note->description);
    }
}
