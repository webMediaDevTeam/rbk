<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Page « Rappels » (ReminderController) : création d'un rappel par une issue
 * d'appel, listage / comptage des rappels en attente du commercial connecté
 * et prise en compte (« Terminer »).
 *
 * Seuls les rappels `done_at IS NULL` du connecté sont listés et comptés ;
 * le filtre par type oppose CALL_BACK (page « Rappels ») et
 * BV_VOICEMAIL (page « Auto-rappels »).
 */
class ReminderControllerTest extends TestCase
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
            'status' => 'AVAILABLE',
            'phone' => '514-555-0100',
        ], $attrs));
    }

    private function makeReservation(Client $client, User $commercial, array $attrs = []): Reservation
    {
        return Reservation::create(array_merge([
            'client_id' => $client->id,
            'comercial_id' => $commercial->id,
            'status' => Reservation::STATUS_PENDING,
        ], $attrs));
    }

    /** Rappel planifié (table `rappels`). */
    private function makeRappel(Reservation $reservation, ?Carbon $at): ?Rappel
    {
        if ($at === null) {
            return null;
        }

        return Rappel::create([
            'client_id' => $reservation->client_id,
            'comercial_id' => $reservation->comercial_id,
            'reservation_id' => $reservation->id,
            'reminder_date' => $at,
        ]);
    }

    /** Rappel CALL_BACK échu du commercial connecté. */
    private function makeDueCallBackRappel(User $commercial): Rappel
    {
        return $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->subMinute()
        );
    }

    // ------------------------------------------------------ Création de rappel

    public function test_outcome_call_back_with_future_recall_at_creates_pending_reminder(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial);

        // +2 jours : date future exigée par la validation (`after:now`).
        $recallAt = now()->addDays(2)->startOfMinute();

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'CALL_BACK',
            'recall_at' => $recallAt->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', 'RESERVED');

        $this->assertDatabaseCount('rappels', 1);

        $rappel = Rappel::firstOrFail();
        $this->assertSame($client->id, $rappel->client_id);
        $this->assertSame($commercial->id, $rappel->comercial_id);
        $this->assertSame($reservation->id, $rappel->reservation_id);
        $this->assertNull($rappel->done_at, 'Le rappel est créé en attente.');
        $this->assertEqualsWithDelta($recallAt->timestamp, $rappel->reminder_date->timestamp, 5);

        $this->assertSame(Reservation::STATUS_CALL_BACK, $reservation->fresh()->status);
    }

    public function test_outcome_bv_schedules_automatic_reminder_three_days_ahead(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        // BV sans saisie de date : rappel automatique à RECALL_DAYS (3 j).
        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'BV'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('rappels', 1);

        $rappel = Rappel::firstOrFail();
        $this->assertNull($rappel->done_at);
        $this->assertEqualsWithDelta(
            now()->addDays(3)->timestamp,
            $rappel->reminder_date->timestamp,
            60
        );
        $this->assertSame($reservation->id, $rappel->reservation_id);
        $this->assertSame(Reservation::STATUS_BV_VOICEMAIL, $reservation->fresh()->status);
        $this->assertSame(1, $reservation->fresh()->bv_count);
    }

    // ------------------------------------------------------------- Listage index

    public function test_index_lists_only_own_pending_call_back_reminders(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        $own = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDays(1)
        );
        // BV : page « Auto-rappels », pas la page « Rappels ».
        $bv = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->addDays(1)
        );
        // Rappel d'un autre commercial.
        $foreign = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $other,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDays(1)
        );
        // Déjà terminé : sort des listes.
        $done = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->subMinute()
        );
        $done->markAsDone(null);

        Sanctum::actingAs($commercial);

        $ids = collect($this->getJson('/api/v1/reminders')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$own->id], $ids->all());
        $this->assertFalse($ids->contains($bv->id), 'Les BV sont sur la page Auto-rappels.');
        $this->assertFalse($ids->contains($foreign->id), 'Rappels des autres commerciaux exclus.');
        $this->assertFalse($ids->contains($done->id), 'Les rappels terminés sont exclus.');
    }

    public function test_index_type_bv_lists_only_bv_voicemail_reminders(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        $bv = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->addDays(1)
        );
        $callBack = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDays(1)
        );
        $foreignBv = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $other,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->addDays(1)
        );

        Sanctum::actingAs($commercial);

        $ids = collect($this->getJson('/api/v1/reminders?type=BV')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$bv->id], $ids->all());
        $this->assertFalse($ids->contains($callBack->id));
        $this->assertFalse($ids->contains($foreignBv->id));
    }

    public function test_index_rejects_unknown_type(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/reminders?type=NON_EXISTENT')->assertStatus(422);
    }

    // ------------------------------------------------------------------ Compteur

    public function test_count_only_counts_due_reminders_per_type(): void
    {
        $commercial = $this->makeCommercial();

        // CALL_BACK échu -> compté (défaut de la page « Rappels »).
        $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->subMinute()
        );
        // CALL_BACK futur -> jamais compté.
        $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDay()
        );
        // BV échu -> compté uniquement avec `?type=BV`.
        $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->subMinute()
        );
        // BV futur -> jamais compté.
        $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->addDay()
        );

        Sanctum::actingAs($commercial);

        // Défaut : seul le CALL_BACK échu (1 sur 2) compte.
        $this->getJson('/api/v1/reminders/count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->getJson('/api/v1/reminders/count?type=CALL_BACK')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->getJson('/api/v1/reminders/count?type=BV')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    // ------------------------------------------------------- Rappel « Terminer »

    public function test_done_marks_reminder_and_removes_it_from_index_and_count(): void
    {
        $commercial = $this->makeCommercial();
        $rappel = $this->makeDueCallBackRappel($commercial);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/reminders/count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->postJson("/api/v1/reminders/{$rappel->id}/done", [
            'note' => 'Rappel fait jeudi matin',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $rappel->refresh();
        $this->assertNotNull($rappel->done_at, 'Le rappel est marqué comme terminé.');
        $this->assertSame('Rappel fait jeudi matin', $rappel->done_note);

        // Il quitte la liste et le compteur du menu.
        $this->assertCount(0, $this->getJson('/api/v1/reminders')->assertOk()->json('data'));
        $this->getJson('/api/v1/reminders/count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        // La note rejoint l'historique du client.
        $note = Note::where('client_id', $rappel->client_id)->where('type', Note::TYPE_NOTE)->firstOrFail();
        $this->assertSame('Rappel fait jeudi matin', $note->description);
        $this->assertSame($commercial->id, $note->sender_id);
    }

    public function test_done_rejects_note_longer_than_eight_words(): void
    {
        $commercial = $this->makeCommercial();
        $rappel = $this->makeDueCallBackRappel($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/reminders/{$rappel->id}/done", [
            'note' => 'un deux trois quatre cinq six sept huit neuf',
        ])->assertStatus(422);

        $this->assertNull($rappel->fresh()->done_at, 'Refus : le rappel reste en attente.');
        $this->assertSame(0, Note::count());
    }

    public function test_done_returns_404_for_unknown_reminder(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/reminders/11111111-1111-1111-1111-111111111111/done')
            ->assertStatus(404);
    }

    public function test_done_returns_404_for_another_commercials_reminder(): void
    {
        $commercial = $this->makeCommercial();
        $rappel = $this->makeDueCallBackRappel($commercial);

        Sanctum::actingAs($this->makeCommercial());

        $this->postJson("/api/v1/reminders/{$rappel->id}/done")->assertStatus(404);

        $this->assertNull($rappel->fresh()->done_at, 'Le rappel d\'un autre reste intact.');
        $this->assertSame(0, Note::count());
    }

    public function test_done_returns_404_when_reminder_is_already_done(): void
    {
        $commercial = $this->makeCommercial();
        $rappel = $this->makeDueCallBackRappel($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/reminders/{$rappel->id}/done")->assertOk();

        $doneAt = $rappel->fresh()->done_at;
        $this->assertNotNull($doneAt);

        // Déjà terminé : introuvable pour la suite.
        $this->postJson("/api/v1/reminders/{$rappel->id}/done")->assertStatus(404);
        $this->assertEquals($doneAt, $rappel->fresh()->done_at);
    }
}
