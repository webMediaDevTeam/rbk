<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La frise d'interactions (`NoteTimeline`) n'affiche « Voir l'appel » (journal
 * + enregistrement RingCentral) que si la note porte un `call_log_id`. Côté
 * commercial, `GET /clients/{id}/notes` renvoie le modèle `Note` complet (le
 * champ est donc présent) ; côté admin, `GET /commercials/clients/{id}` rend
 * une **projection** des notes — le `call_log_id` doit y figurer, sinon le
 * lien disparaît pour un ADMIN / SUPER_ADMIN.
 */
class AdminClientCallLogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
    }

    /** Client + note d'appel (`type = NOTE` + `call_log_id`), comme posée par
     *  le workflow RingCentral. */
    private function makeClientWithCallNote(): array
    {
        $client = Client::create([
            'name' => 'MP solutions inc.',
            'phone' => '819-762-2818',
            'status' => Client::STATUS_RESERVED,
        ]);

        $call = CallLog::create([
            'client_id' => $client->id,
            'ringcentral_session_id' => 'rc-session-1',
            'direction' => 'Outbound',
            'to_number' => '+1 (819) 762-2818',
        ]);

        $note = Note::create([
            'client_id' => $client->id,
            'call_log_id' => $call->id,
            'sender_id' => Note::SENDER_SYSTEM,
            'type' => Note::TYPE_NOTE,
            'description' => 'Appel vers '.$client->phone,
        ]);

        return [$client, $call, $note];
    }

    public function test_admin_client_detail_exposes_call_log_id_on_call_note(): void
    {
        [$client, $call, $note] = $this->makeClientWithCallNote();
        Sanctum::actingAs($this->makeAdmin());

        $noteRow = collect($this->getJson("/api/v1/commercials/clients/{$client->id}")
            ->assertOk()
            ->json('data.client.notes'))
            ->firstWhere('id', $note->id);

        $this->assertNotNull($noteRow, 'La note d\'appel doit être présente dans la fiche admin.');
        // C'est ce champ qui déclenche l'affichage de « Voir l'appel ».
        $this->assertArrayHasKey('call_log_id', $noteRow);
        $this->assertSame($call->id, $noteRow['call_log_id']);
    }

    public function test_plain_comment_note_has_null_call_log_id(): void
    {
        $client = Client::create([
            'name' => 'Commentaire',
            'phone' => '514-555-0100',
            'status' => Client::STATUS_RESERVED,
        ]);
        $note = Note::create([
            'client_id' => $client->id,
            'sender_id' => $this->makeAdmin()->id,
            'type' => Note::TYPE_NOTE,
            'description' => 'simple commentaire',
        ]);
        Sanctum::actingAs($this->makeAdmin());

        $noteRow = collect($this->getJson("/api/v1/commercials/clients/{$client->id}")
            ->assertOk()
            ->json('data.client.notes'))
            ->firstWhere('id', $note->id);

        $this->assertArrayHasKey('call_log_id', $noteRow);
        $this->assertNull($noteRow['call_log_id']);
    }
}
