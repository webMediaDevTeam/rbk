<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Journal d'interactions d'un client (`notes`) :
 *
 *   GET    /api/v1/clients/{clientId}/notes
 *   POST   /api/v1/notes
 *   DELETE /api/v1/notes/{id}
 *
 * Seul le type `NOTE` est créable / supprimable par l'API : les événements
 * du workflow (YES, NO, BV…) sont écrits par le workflow et les crons.
 */
class NoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCommercial(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_RESERVED,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    private function makeNote(Client $client, User $sender, array $attrs = []): Note
    {
        return Note::create(array_merge([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => Note::TYPE_NOTE,
            'description' => 'Commentaire de suivi',
        ], $attrs));
    }

    // ------------------------------------------------------------------ Index

    public function test_index_returns_client_notes_newest_first_with_sender_and_skips_other_clients(): void
    {
        $commercial = $this->makeCommercial([
            'first_name' => 'Jean',
            'last_name' => 'Tremblay',
        ]);
        $client = $this->makeClient();
        $otherClient = $this->makeClient(['name' => 'Autre Construction']);

        // Trois notes créées à des instants distincts (précision à la seconde).
        $base = now();

        Carbon::setTestNow($base->copy()->subMinutes(30));
        $older = $this->makeNote($client, $commercial, ['description' => 'Premier commentaire']);

        Carbon::setTestNow($base->copy()->subMinutes(10));
        $newer = $this->makeNote($client, $commercial, ['description' => 'Deuxième commentaire']);

        Carbon::setTestNow($base->copy()->subMinutes(5));
        $foreign = $this->makeNote($otherClient, $commercial, ['description' => 'Hors client ciblé']);

        Carbon::setTestNow();

        Sanctum::actingAs($commercial);

        $response = $this->getJson("/api/v1/clients/{$client->id}/notes")
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');

        // Tri `created_at` décroissant, notes des autres clients exclues.
        $this->assertCount(2, $data);
        $ids = array_column($data, 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertNotContains($foreign->id, $ids);

        // Émetteur préchargé (id + prénom, nom, courriel).
        $sender = $data[0]['sender'];
        $this->assertSame($commercial->id, $sender['id']);
        $this->assertSame('Jean', $sender['first_name']);
        $this->assertSame('Tremblay', $sender['last_name']);
        $this->assertSame($commercial->email, $sender['email']);
        $this->assertSame(Note::TYPE_NOTE, $data[0]['type']);
        $this->assertSame('Deuxième commentaire', $data[0]['description']);
    }

    // ------------------------------------------------------------------ Store

    public function test_store_creates_a_note_sent_by_the_connected_commercial(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/notes', [
            'client_id' => $client->id,
            'description' => 'Client rappelé jeudi',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', Note::TYPE_NOTE)
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.sender.id', $commercial->id);

        $this->assertDatabaseCount('notes', 1);
        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_NOTE,
            'description' => 'Client rappelé jeudi',
        ]);
    }

    public function test_store_accepts_an_explicit_note_type(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/notes', [
            'client_id' => $client->id,
            'type' => Note::TYPE_NOTE,
            'description' => 'Suivi explicite',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', Note::TYPE_NOTE);

        $this->assertSame(1, Note::where('type', Note::TYPE_NOTE)->count());
    }

    public function test_store_validates_description_and_client_id(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        Sanctum::actingAs($commercial);

        // Description manquante.
        $this->postJson('/api/v1/notes', ['client_id' => $client->id])
            ->assertStatus(422);

        // Description non-string.
        $this->postJson('/api/v1/notes', [
            'client_id' => $client->id,
            'description' => ['liste', 'de', 'mots'],
        ])->assertStatus(422);

        // client_id sans forme d'UUID.
        $this->postJson('/api/v1/notes', [
            'client_id' => 'pas-un-uuid',
            'description' => 'Commentaire valide',
        ])->assertStatus(422);

        // UUID bien formé mais inexistant.
        $this->postJson('/api/v1/notes', [
            'client_id' => (string) Str::uuid(),
            'description' => 'Commentaire valide',
        ])->assertStatus(422);

        $this->assertSame(0, Note::count());
    }

    public function test_store_rejects_workflow_event_types(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        Sanctum::actingAs($commercial);

        // Seul NOTE est créable : les événements sont écrits par le workflow.
        $this->postJson('/api/v1/notes', [
            'client_id' => $client->id,
            'type' => Note::TYPE_YES,
            'description' => 'Fausse issue',
        ])->assertStatus(422);

        $this->assertSame(0, Note::count());
    }

    public function test_store_rejects_more_than_eight_words_in_description(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/notes', [
            'client_id' => $client->id,
            'description' => 'un deux trois quatre cinq six sept huit neuf',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['description']);

        $this->assertSame(0, Note::count());
    }

    // ---------------------------------------------------------------- Destroy

    public function test_owner_can_delete_their_own_comment(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        $note = $this->makeNote($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->deleteJson("/api/v1/notes/{$note->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_another_commercial_cannot_delete_a_comment(): void
    {
        $owner = $this->makeCommercial();
        $intruder = $this->makeCommercial();
        $client = $this->makeClient();
        $note = $this->makeNote($client, $owner);

        Sanctum::actingAs($intruder);

        $this->deleteJson("/api/v1/notes/{$note->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('notes', ['id' => $note->id]);
    }

    /**
     * Le contrôleur autorise l'admin (`owner OR ADMIN/SUPER_ADMIN`), mais la
     * route vit dans le groupe `CheckRole:COMERCIAL` : un admin n'atteint
     * jamais cette règle — à l'inverse de `reservation-groups/{id}`, sortie
     * du groupe expressément pour cela (routes/api/commercial.php).
     */
    public function test_admin_is_rejected_by_role_middleware_before_the_controller_admin_rule(): void
    {
        $commercial = $this->makeCommercial();
        $admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $client = $this->makeClient();
        $note = $this->makeNote($client, $commercial);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/notes/{$note->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Accès non autorisé.');

        $this->assertDatabaseHas('notes', ['id' => $note->id]);
    }

    public function test_workflow_event_notes_are_immutable_even_for_their_author(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();
        $event = $this->makeNote($client, $commercial, [
            'type' => Note::TYPE_YES,
            'description' => 'très intéressé',
        ]);

        Sanctum::actingAs($commercial);

        $this->deleteJson("/api/v1/notes/{$event->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Seuls les commentaires peuvent être supprimés.');

        $this->assertDatabaseHas('notes', ['id' => $event->id]);
    }

    public function test_delete_unknown_note_returns_404(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        $this->deleteJson('/api/v1/notes/'.Str::uuid())->assertNotFound();
    }
}
