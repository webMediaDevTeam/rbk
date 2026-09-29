<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CallWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests du workflow d'appel (docs/RULES.md) — vocabulaire du modèle
 * (docs/models.puml) : événements dans `notes`, rappels dans `rappels`.
 * Priorité : CallWorkflowService.
 */
class CallWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private CallWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = app(CallWorkflowService::class);
    }

    // ---------------------------------------------------------------- Helpers

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
            'municipality' => 'Québec',
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

    /** Rappel existant (les rappels ne vivent plus dans la réservation). */
    private function makeRappel(Reservation $reservation, ?Carbon $at = null): Rappel
    {
        return Rappel::create([
            'client_id' => $reservation->client_id,
            'comercial_id' => $reservation->comercial_id,
            'reservation_id' => $reservation->id,
            'reminder_date' => $at ?? now()->subMinute(),
        ]);
    }

    /** Le rappel de cette réservation est-il annulé ? */
    private function rappelCancelled(Reservation $reservation): bool
    {
        return Rappel::where('reservation_id', $reservation->id)->doesntExist();
    }

    // ------------------------------------------------------------------ YES

    public function test_yes_moves_client_and_reservation_to_confirmed(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED', 'returned_at' => now()->addDays(10)]);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_BV_VOICEMAIL,
        ]);
        $this->makeRappel($reservation, now()->addDays(3));

        $result = $this->workflow->apply($client, $reservation, Note::TYPE_YES, [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('CONFIRMED', $client->status);
        $this->assertSame('YES', $reservation->status);
        $this->assertNull($client->returned_at, 'CONFIRMED vide le compte à rebours de retour.');
        $this->assertTrue($this->rappelCancelled($reservation), 'Le rappel doit être annulé par un YES.');
        $this->assertSame('CONFIRMED', $result['client_status']);
        $this->assertFalse($result['blacklisted']);

        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'sender_id' => $commercial->id,
            'type' => 'YES',
            'description' => null, // note optionnelle
        ]);
    }

    // ------------------------------------------------------------------- NO

    public function test_no_blocks_client_for_three_months_without_blacklist(): void
    {
        $commercial = $this->makeCommercial();
        $this->makeCommercial(); // 2e commercial actif : pas d'auto-blacklist
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial);
        $this->makeRappel($reservation);

        $this->workflow->apply($client, $reservation, Note::TYPE_NO, [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('UNAVAILABLE', $client->status);
        $this->assertSame('NO', $reservation->status);
        $this->assertFalse($client->is_blacklisted);
        $this->assertEqualsWithDelta(
            now()->addMonths(3)->timestamp,
            $client->returned_at->timestamp,
            5
        );
        $this->assertTrue($this->rappelCancelled($reservation));
    }

    public function test_no_auto_blacklists_when_all_active_commercials_refused(): void
    {
        $commercialA = $this->makeCommercial();
        $commercialB = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservationA = $this->makeReservation($client, $commercialA);
        $reservationB = $this->makeReservation($client, $commercialB);

        // 1er NO : indispo 3 mois, pas encore de liste noire
        $first = $this->workflow->apply($client->fresh(), $reservationA, Note::TYPE_NO, [], $commercialA);
        $this->assertFalse($first['blacklisted']);

        // 2e NO (dernier commercial actif) : auto-blacklist
        $second = $this->workflow->apply($client->fresh(), $reservationB, Note::TYPE_NO, [], $commercialB);

        $client->refresh();

        $this->assertTrue($second['blacklisted']);
        $this->assertSame('BLACKLISTED', $client->status);
        $this->assertTrue($client->is_blacklisted);
        $this->assertNull($client->returned_at, 'La liste noire vide returned_at.');

        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'type' => 'BLACKLISTED',
            'description' => 'Tous les employés actifs ont répondu NON.',
        ]);
    }

    // ------------------------------------------------- BV / CALL_BACK

    public function test_first_bv_keeps_client_reserved_and_schedules_recall_in_3_days(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');

        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);
        $reservation = $this->makeReservation($client, $commercial);

        $result = $this->workflow->apply($client, $reservation, Note::TYPE_BV, [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('RESERVED', $client->status);
        $this->assertSame('BV_VOICEMAIL', $reservation->status);
        $this->assertSame(1, $reservation->bv_count);
        $this->assertSame(0, $reservation->injoinable_count);

        $rappel = Rappel::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $rappel->reminder_date->timestamp, 5);
        $this->assertSame([3, 'JOUR'], $rappel->delay());
        $this->assertSame('Rappel configuré sous 3 jours.', $result['message']);
        $this->assertNull($client->returned_at);

        Carbon::setTestNow();
    }

    public function test_second_bv_blocks_client_21_days(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_BV_VOICEMAIL,
            'bv_count' => 1,
        ]);
        $this->makeRappel($reservation, now()->addDays(3));

        $result = $this->workflow->apply($client, $reservation, Note::TYPE_BV, [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('UNAVAILABLE', $client->status);
        $this->assertSame(2, $reservation->bv_count);
        $this->assertSame('BV_VOICEMAIL', $reservation->status);
        $this->assertTrue($this->rappelCancelled($reservation), 'Pas de rappel après épuisement des tentatives.');
        $this->assertEqualsWithDelta(
            now()->addDays(21)->timestamp,
            $client->returned_at->timestamp,
            5
        );
        $this->assertSame('Tentatives épuisées. Client indisponible pour 21 jours.', $result['message']);
    }

    public function test_call_back_counts_separately_and_schedules_recall(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);
        $reservation = $this->makeReservation($client, $commercial);

        $this->workflow->apply($client, $reservation, Note::TYPE_CALL_BACK, [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('RESERVED', $client->status);
        $this->assertSame('CALL_BACK', $reservation->status);
        $this->assertSame(1, $reservation->injoinable_count);
        $this->assertSame(0, $reservation->bv_count);

        $rappel = Rappel::where('reservation_id', $reservation->id)->firstOrFail();
        // Sans saisie : repli sur le rappel automatique à 3 jours.
        $this->assertSame([3, 'JOUR'], $rappel->delay());
    }

    public function test_call_back_uses_custom_recall_datetime(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');

        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);
        $reservation = $this->makeReservation($client, $commercial);

        $result = $this->workflow->apply($client, $reservation, Note::TYPE_CALL_BACK, [
            'recall_at' => '2026-09-24 15:30:00', // datetime custom choisi par l'employé
        ], $commercial);

        $reservation->refresh();

        $this->assertSame('CALL_BACK', $reservation->status);
        $rappel = Rappel::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertEqualsWithDelta(
            Carbon::parse('2026-09-24 15:30:00')->timestamp,
            $rappel->reminder_date->timestamp,
            5
        );
        // 5h30 -> 5 heures (unité la plus lisible).
        $this->assertSame([5, 'HEURE'], $rappel->delay());
        $this->assertSame('Rappel planifié.', $result['message']);
        $this->assertNull($client->fresh()->returned_at);

        Carbon::setTestNow();
    }

    public function test_bv_ignores_custom_recall_and_stays_at_three_days(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');

        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);
        $reservation = $this->makeReservation($client, $commercial);

        // Le rappel automatique BV ne doit jamais être influencé par une saisie.
        $this->workflow->apply($client, $reservation, Note::TYPE_BV, [
            'recall_at' => '2026-09-24 15:30:00',
        ], $commercial);

        $reservation->refresh();

        $this->assertSame('BV_VOICEMAIL', $reservation->status);
        $rappel = Rappel::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $rappel->reminder_date->timestamp, 5);
        $this->assertSame([3, 'JOUR'], $rappel->delay());

        Carbon::setTestNow();
    }

    public function test_second_call_back_blocks_client_21_days(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_CALL_BACK,
            'injoinable_count' => 1,
        ]);
        $this->makeRappel($reservation, now()->addDays(3));

        $this->workflow->apply($client, $reservation, Note::TYPE_CALL_BACK, [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('UNAVAILABLE', $client->status);
        $this->assertSame(2, $reservation->injoinable_count);
        $this->assertEqualsWithDelta(
            now()->addDays(21)->timestamp,
            $client->returned_at->timestamp,
            5
        );
    }

    // ------------------------------------------------------- Rappel expiré (cron)

    public function test_expired_recall_below_limit_increments_counter_and_keeps_reservation(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_BV_VOICEMAIL,
            'bv_count' => 0,
        ]);
        $rappel = $this->makeRappel($reservation, now()->subMinute());

        $blocked = $this->workflow->handleRecallExpired($rappel);

        $client->refresh();
        $reservation->refresh();

        $this->assertFalse($blocked);
        $this->assertSame(1, $reservation->bv_count);
        $this->assertTrue($this->rappelCancelled($reservation), 'Rappel retiré => le client réapparaît dans les listes.');
        $this->assertSame('RESERVED', $client->status, 'Sous le seuil, le client reste RESERVED.');
        $this->assertNull($client->returned_at);
        $this->assertSame(1, Reservation::count(), 'La réservation ne doit jamais être supprimée.');
    }

    public function test_expired_recall_at_limit_blocks_client_but_keeps_reservation(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_BV_VOICEMAIL,
            'bv_count' => 1,
        ]);
        $rappel = $this->makeRappel($reservation, now()->subMinute());

        $blocked = $this->workflow->handleRecallExpired($rappel);

        $client->refresh();
        $reservation->refresh();

        $this->assertTrue($blocked);
        $this->assertSame(2, $reservation->bv_count);
        $this->assertSame('UNAVAILABLE', $client->status);
        $this->assertEqualsWithDelta(
            now()->addDays(21)->timestamp,
            $client->returned_at->timestamp,
            5
        );
        $this->assertTrue($this->rappelCancelled($reservation));
        $this->assertSame(1, Reservation::count(), 'La réservation ne doit jamais être supprimée.');
    }

    public function test_expired_recall_for_call_back_uses_injoinable_counter(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_CALL_BACK,
            'injoinable_count' => 0,
            'bv_count' => 1, // ne doit pas être touché
        ]);
        $rappel = $this->makeRappel($reservation, now()->subMinute());

        $blocked = $this->workflow->handleRecallExpired($rappel);

        $reservation->refresh();

        $this->assertFalse($blocked);
        $this->assertSame(1, $reservation->injoinable_count);
        $this->assertSame(1, $reservation->bv_count, 'Le compteur BV ne doit pas bouger.');
        $this->assertSame('CALL_BACK', $reservation->status);
    }

    public function test_expired_recall_on_blacklisted_client_only_clears_rappel(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'BLACKLISTED', 'is_blacklisted' => true]);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_BV_VOICEMAIL,
            'bv_count' => 1,
        ]);
        $rappel = $this->makeRappel($reservation, now()->subMinute());

        $blocked = $this->workflow->handleRecallExpired($rappel);

        $this->assertFalse($blocked);
        $this->assertTrue($this->rappelCancelled($reservation));
        $this->assertSame(1, $reservation->fresh()->bv_count, 'Aucun compteur incrémenté pour un blacklisté.');
        $this->assertSame('BLACKLISTED', $client->fresh()->status);
    }

    // -------------------------------------------------------------- BLACKLIST

    public function test_blacklist_marks_client_and_keeps_reservations(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient([
            'status' => 'RESERVED',
            'returned_at' => now()->addMonths(3),
        ]);
        $this->makeReservation($client, $commercial);

        $result = $this->workflow->apply($client, null, Note::TYPE_BLACKLISTED, [], $commercial);

        $client->refresh();

        $this->assertSame('BLACKLISTED', $client->status);
        $this->assertTrue($client->is_blacklisted);
        $this->assertNull($client->returned_at);
        $this->assertTrue($result['blacklisted']);
        $this->assertSame(1, Reservation::count(), 'La liste noire ne supprime pas les réservations.');
        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'sender_id' => $commercial->id,
            'type' => 'BLACKLISTED',
            'description' => null,
        ]);
    }

    public function test_unknown_event_is_rejected(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        $this->expectException(\InvalidArgumentException::class);

        $this->workflow->apply($client, null, 'WHATEVER', [], $commercial);
    }

    // --------------------------------------------------------- Limite 8 mots

    public function test_outcome_note_is_limited_to_eight_words(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        $nineWords = 'un deux trois quatre cinq six sept huit neuf';

        try {
            Note::create([
                'client_id' => $client->id,
                'sender_id' => $commercial->id,
                'type' => Note::TYPE_BV,
                'description' => $nineWords,
            ]);
            $this->fail('Une note de 9 mots doit être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('description', $e->errors());
            $this->assertSame('La note ne peut pas dépasser 8 mots.', $e->errors()['description'][0]);
        }

        $this->assertSame(0, Note::count());
    }

    public function test_comment_note_is_limited_to_eight_words(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        try {
            Note::create([
                'client_id' => $client->id,
                'sender_id' => $commercial->id,
                'type' => Note::TYPE_NOTE,
                'description' => 'un deux trois quatre cinq six sept huit neuf',
            ]);
            $this->fail('Une note de 9 mots doit être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('description', $e->errors());
        }

        $this->assertSame(0, Note::count());
    }

    public function test_eight_words_exactly_is_allowed(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        $note = Note::create([
            'client_id' => $client->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_NOTE,
            'description' => 'un deux trois quatre cinq six sept huit',
        ]);

        $this->assertSame('un deux trois quatre cinq six sept huit', $note->description);
        $this->assertSame(1, Note::count());
    }

    public function test_apply_accepts_empty_note_on_every_event(): void
    {
        $commercial = $this->makeCommercial();
        $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial);

        foreach ([Note::TYPE_YES, Note::TYPE_NO, Note::TYPE_BV] as $index => $event) {
            $freshClient = $client->fresh();
            $freshReservation = $index === 0 ? $reservation->fresh() : null;

            $result = $this->workflow->apply(
                $freshClient,
                $freshReservation,
                $event,
                ['note' => '   '], // note vide / blanche
                $commercial
            );

            $this->assertIsString($result['message']);
        }

        $this->assertSame(3, Note::calls()->count());
        $this->assertSame(0, Note::calls()->whereNotNull('description')->count());
    }

    // ------------------------------------------------------- Disponibilité / cron

    public function test_available_scope_ignores_passed_returned_at(): void
    {
        $available = $this->makeClient(['status' => 'AVAILABLE']);
        $availableWithPastReturn = $this->makeClient([
            'status' => 'AVAILABLE',
            'returned_at' => now()->subDay(),
        ]);
        $availableWithFutureReturn = $this->makeClient([
            'status' => 'AVAILABLE',
            'returned_at' => now()->addDays(5),
        ]);
        $blocked = $this->makeClient([
            'status' => 'UNAVAILABLE',
            'returned_at' => now()->subDay(), // cron pas encore passé
        ]);

        $ids = Client::available()->pluck('id');

        $this->assertTrue($ids->contains($available->id));
        $this->assertTrue($ids->contains($availableWithPastReturn->id));
        $this->assertFalse($ids->contains($availableWithFutureReturn->id));
        $this->assertFalse($ids->contains($blocked->id));
    }

    public function test_reactivation_command_returns_expired_clients_to_available(): void
    {
        $expired = $this->makeClient([
            'status' => 'UNAVAILABLE',
            'returned_at' => now()->subMinute(),
        ]);
        $stillBlocked = $this->makeClient([
            'status' => 'UNAVAILABLE',
            'returned_at' => now()->addDays(10),
        ]);

        $this->artisan('clients:reactivate')->assertExitCode(0);

        $expired->refresh();
        $stillBlocked->refresh();

        $this->assertSame('AVAILABLE', $expired->status);
        $this->assertNull($expired->returned_at);
        $this->assertSame('UNAVAILABLE', $stillBlocked->status);
        $this->assertNotNull($stillBlocked->returned_at);

        // Le retour est historisé dans le journal (émetteur SYSTEM).
        $this->assertDatabaseHas('notes', [
            'client_id' => $expired->id,
            'sender_id' => Note::SENDER_SYSTEM,
            'type' => Note::TYPE_RETURNED_TO_AVAILABLE,
        ]);
        $this->assertDatabaseMissing('notes', [
            'client_id' => $stillBlocked->id,
            'type' => Note::TYPE_RETURNED_TO_AVAILABLE,
        ]);
    }

    public function test_process_timeouts_command_handles_expired_recall_without_deleting_reservation(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_BV_VOICEMAIL,
            'bv_count' => 0,
        ]);
        $expired = $this->makeRappel($reservation, now()->subMinutes(5));

        $futureClient = $this->makeClient(['status' => 'RESERVED']);
        $futureReservation = $this->makeReservation($futureClient, $commercial, [
            'status' => Reservation::STATUS_BV_VOICEMAIL,
        ]);
        $futureRappel = $this->makeRappel($futureReservation, now()->addDay());

        $this->artisan('clients:process-timeouts')->assertExitCode(0);

        $this->assertSame(1, $reservation->fresh()->bv_count);
        $this->assertTrue($this->rappelCancelled($reservation));
        $this->assertSame('RESERVED', $client->fresh()->status);
        $this->assertNotNull($futureRappel->fresh(), 'Un rappel futur ne doit pas être traité.');
        $this->assertFalse($this->rappelCancelled($futureReservation));
        $this->assertSame(2, Reservation::count());
    }

    // ------------------------------------------------- Aucune expiration de résa

    public function test_reservations_table_has_no_expires_at_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('reservations', 'expires_at'),
            'expires_at a été supprimée (plus aucune expiration de réservation).'
        );
        $this->assertTrue(Schema::hasColumn('clients', 'returned_at'));
        $this->assertFalse(Schema::hasColumn('clients', 'blocked_until'));
        $this->assertFalse(method_exists(Reservation::class, 'pendingFor'));
    }

    /** Les rappels ont quitté `reservations` pour leur propre table. */
    public function test_rappels_live_in_their_own_table(): void
    {
        $this->assertTrue(Schema::hasTable('rappels'));
        $this->assertFalse(Schema::hasColumn('reservations', 'recall_at'));
        $this->assertFalse(Schema::hasColumn('reservations', 'rappel_after'));
        $this->assertFalse(Schema::hasColumn('reservations', 'rappel_type'));
        $this->assertFalse(Schema::hasTable('call_outcomes'), 'call_outcomes est fusionné dans notes.');
    }
}
