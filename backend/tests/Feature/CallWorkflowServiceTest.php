<?php

namespace Tests\Feature;

use App\Models\CallOutcome;
use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CallWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests du workflow d'appel (docs/RULES.md).
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
            'rbq_data' => ['name' => 'ACME Construction'],
            'status' => 'AVAILABLE',
            'municipality' => 'Québec',
        ], $attrs));
    }

    private function makeReservation(Client $client, User $commercial, array $attrs = []): Reservation
    {
        return Reservation::create(array_merge([
            'client_id' => $client->id,
            'comercial_id' => $commercial->id,
            'status' => 'EN_ATTENT',
        ], $attrs));
    }

    // ------------------------------------------------------------------ OUI

    public function test_oui_moves_client_and_reservation_to_success(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED', 'returned_at' => now()->addDays(10)]);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => 'BV',
            'recall_at' => now()->addDays(3),
        ]);

        $result = $this->workflow->apply($client, $reservation, 'OUI', [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('SUCCESS', $client->status);
        $this->assertSame('OUI', $reservation->status);
        $this->assertNull($reservation->recall_at, 'Le rappel doit être annulé par un OUI.');
        $this->assertTrue($result['client_status'] === 'SUCCESS');
        $this->assertFalse($result['blacklisted']);

        $this->assertDatabaseHas('call_outcomes', [
            'client_id' => $client->id,
            'comercial_id' => $commercial->id,
            'outcome' => 'OUI',
            'note' => null, // note optionnelle
        ]);
    }

    // ------------------------------------------------------------------ NON

    public function test_non_blocks_client_for_three_months_without_blacklist(): void
    {
        $commercial = $this->makeCommercial();
        $this->makeCommercial(); // 2e commercial actif : pas d'auto-blacklist
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial);

        $this->workflow->apply($client, $reservation, 'NON', [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('UNAVAILABLE_TEMP', $client->status);
        $this->assertSame('NON', $reservation->status);
        $this->assertFalse($client->is_blacklisted);
        $this->assertEqualsWithDelta(
            now()->addMonths(3)->timestamp,
            $client->returned_at->timestamp,
            5
        );
        $this->assertNull($reservation->recall_at);
    }

    public function test_non_auto_blacklists_when_all_active_commercials_refused(): void
    {
        $commercialA = $this->makeCommercial();
        $commercialB = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservationA = $this->makeReservation($client, $commercialA);
        $reservationB = $this->makeReservation($client, $commercialB);

        // 1er NON : indispo 3 mois, pas encore de liste noire
        $first = $this->workflow->apply($client->fresh(), $reservationA, 'NON', [], $commercialA);
        $this->assertFalse($first['blacklisted']);

        // 2e NON (dernier commercial actif) : auto-blacklist
        $second = $this->workflow->apply($client->fresh(), $reservationB, 'NON', [], $commercialB);

        $client->refresh();

        $this->assertTrue($second['blacklisted']);
        $this->assertSame('BLACKLISTED', $client->status);
        $this->assertTrue($client->is_blacklisted);
        $this->assertNull($client->returned_at, 'La liste noire vide returned_at.');

        $this->assertDatabaseHas('call_outcomes', [
            'client_id' => $client->id,
            'outcome' => 'BLACKLIST',
        ]);
    }

    // ------------------------------------------------------------- BV / INJOINABLE

    public function test_first_bv_keeps_client_reserved_and_schedules_recall_in_3_days(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');

        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);
        $reservation = $this->makeReservation($client, $commercial);

        $result = $this->workflow->apply($client, $reservation, 'BV', [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('RESERVED', $client->status);
        $this->assertSame('BV', $reservation->status);
        $this->assertSame(1, $reservation->bv_count);
        $this->assertSame(0, $reservation->injoinable_count);
        $this->assertEqualsWithDelta(
            now()->addDays(3)->timestamp,
            $reservation->recall_at->timestamp,
            5
        );
        $this->assertSame(3, $reservation->rappel_after);
        $this->assertSame('JOUR', $reservation->rappel_type);
        $this->assertSame('Rappel configuré sous 3 jours.', $result['message']);
        $this->assertNull($client->returned_at);

        Carbon::setTestNow();
    }

    public function test_second_bv_blocks_client_21_days(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => 'BV',
            'bv_count' => 1,
            'recall_at' => now()->addDays(3),
        ]);

        $result = $this->workflow->apply($client, $reservation, 'BV', [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('UNAVAILABLE_TEMP', $client->status);
        $this->assertSame(2, $reservation->bv_count);
        $this->assertSame('BV', $reservation->status);
        $this->assertNull($reservation->recall_at, 'Pas de rappel après épuisement des tentatives.');
        $this->assertEqualsWithDelta(
            now()->addDays(21)->timestamp,
            $client->returned_at->timestamp,
            5
        );
        $this->assertSame('Tentatives épuisées. Client indisponible pour 21 jours.', $result['message']);
    }

    public function test_injoinable_counts_separately_and_displays_as_recall_status(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);
        $reservation = $this->makeReservation($client, $commercial);

        $this->workflow->apply($client, $reservation, 'INJOINABLE', [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('RESERVED', $client->status);
        $this->assertSame('INJOINABLE', $reservation->status); // affiché "à RAPPELER"
        $this->assertSame(1, $reservation->injoinable_count);
        $this->assertSame(0, $reservation->bv_count);
        $this->assertNotNull($reservation->recall_at);
        // Sans saisie : repli sur le rappel automatique à 3 jours.
        $this->assertSame(3, $reservation->rappel_after);
        $this->assertSame('JOUR', $reservation->rappel_type);
    }

    public function test_injoinable_uses_custom_recall_datetime(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');

        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);
        $reservation = $this->makeReservation($client, $commercial);

        $result = $this->workflow->apply($client, $reservation, 'INJOINABLE', [
            'recall_at' => '2026-09-24 15:30:00', // datetime custom choisi par l'employé
        ], $commercial);

        $reservation->refresh();

        $this->assertSame('INJOINABLE', $reservation->status);
        $this->assertEqualsWithDelta(
            Carbon::parse('2026-09-24 15:30:00')->timestamp,
            $reservation->recall_at->timestamp,
            5
        );
        // 5h30 -> 5 heures (unité la plus lisible).
        $this->assertSame(5, $reservation->rappel_after);
        $this->assertSame('HEURE', $reservation->rappel_type);
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
        $this->workflow->apply($client, $reservation, 'BV', [
            'recall_at' => '2026-09-24 15:30:00',
        ], $commercial);

        $reservation->refresh();

        $this->assertSame('BV', $reservation->status);
        $this->assertEqualsWithDelta(
            now()->addDays(3)->timestamp,
            $reservation->recall_at->timestamp,
            5
        );
        $this->assertSame(3, $reservation->rappel_after);
        $this->assertSame('JOUR', $reservation->rappel_type);

        Carbon::setTestNow();
    }

    public function test_second_injoinable_blocks_client_21_days(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => 'INJOINABLE',
            'injoinable_count' => 1,
            'recall_at' => now()->addDays(3),
        ]);

        $this->workflow->apply($client, $reservation, 'INJOINABLE', [], $commercial);

        $client->refresh();
        $reservation->refresh();

        $this->assertSame('UNAVAILABLE_TEMP', $client->status);
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
            'status' => 'BV',
            'bv_count' => 0,
            'recall_at' => now()->subMinute(),
        ]);

        $blocked = $this->workflow->handleRecallExpired($reservation);

        $client->refresh();
        $reservation->refresh();

        $this->assertFalse($blocked);
        $this->assertSame(1, $reservation->bv_count);
        $this->assertNull($reservation->recall_at, 'recall_at vidé => le client réapparaît dans les listes.');
        $this->assertSame('RESERVED', $client->status, 'Sous le seuil, le client reste RESERVED.');
        $this->assertNull($client->returned_at);
        $this->assertSame(1, Reservation::count(), 'La réservation ne doit jamais être supprimée.');
    }

    public function test_expired_recall_at_limit_blocks_client_but_keeps_reservation(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => 'BV',
            'bv_count' => 1,
            'recall_at' => now()->subMinute(),
        ]);

        $blocked = $this->workflow->handleRecallExpired($reservation);

        $client->refresh();
        $reservation->refresh();

        $this->assertTrue($blocked);
        $this->assertSame(2, $reservation->bv_count);
        $this->assertSame('UNAVAILABLE_TEMP', $client->status);
        $this->assertEqualsWithDelta(
            now()->addDays(21)->timestamp,
            $client->returned_at->timestamp,
            5
        );
        $this->assertNull($reservation->recall_at);
        $this->assertSame(1, Reservation::count(), 'La réservation ne doit jamais être supprimée.');
    }

    public function test_expired_recall_for_injoinable_uses_injoinable_counter(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => 'INJOINABLE',
            'injoinable_count' => 0,
            'bv_count' => 1, // ne doit pas être touché
            'recall_at' => now()->subMinute(),
        ]);

        $blocked = $this->workflow->handleRecallExpired($reservation);

        $reservation->refresh();

        $this->assertFalse($blocked);
        $this->assertSame(1, $reservation->injoinable_count);
        $this->assertSame(1, $reservation->bv_count, 'Le compteur BV ne doit pas bouger.');
        $this->assertSame('INJOINABLE', $reservation->status);
    }

    public function test_expired_recall_on_blacklisted_client_only_clears_recall(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'BLACKLISTED', 'is_blacklisted' => true]);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => 'BV',
            'bv_count' => 1,
            'recall_at' => now()->subMinute(),
        ]);

        $blocked = $this->workflow->handleRecallExpired($reservation);

        $this->assertFalse($blocked);
        $this->assertNull($reservation->fresh()->recall_at);
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

        $result = $this->workflow->apply($client, null, 'BLACKLIST', [], $commercial);

        $client->refresh();

        $this->assertSame('BLACKLISTED', $client->status);
        $this->assertTrue($client->is_blacklisted);
        $this->assertNull($client->returned_at);
        $this->assertTrue($result['blacklisted']);
        $this->assertSame(1, Reservation::count(), 'La liste noire ne supprime pas les réservations.');
        $this->assertDatabaseHas('call_outcomes', [
            'client_id' => $client->id,
            'outcome' => 'BLACKLIST',
            'note' => null,
        ]);
    }

    public function test_unknown_outcome_is_rejected(): void
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
            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $commercial->id,
                'outcome' => 'BV',
                'note' => $nineWords,
            ]);
            $this->fail('Une note de 9 mots doit être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
            $this->assertSame('La note ne peut pas dépasser 8 mots.', $e->errors()['note'][0]);
        }

        $this->assertSame(0, CallOutcome::count());
    }

    public function test_note_content_is_limited_to_eight_words(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        try {
            Note::create([
                'client_id' => $client->id,
                'comercial_id' => $commercial->id,
                'type' => 'GENERAL_NOTE',
                'content' => 'un deux trois quatre cinq six sept huit neuf',
            ]);
            $this->fail('Une note de 9 mots doit être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->errors());
        }

        $this->assertSame(0, Note::count());
    }

    public function test_eight_words_exactly_is_allowed(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        $note = Note::create([
            'client_id' => $client->id,
            'comercial_id' => $commercial->id,
            'type' => 'GENERAL_NOTE',
            'content' => 'un deux trois quatre cinq six sept huit',
        ]);

        $this->assertSame('un deux trois quatre cinq six sept huit', $note->content);
        $this->assertSame(1, Note::count());
    }

    public function test_apply_accepts_empty_note_on_every_outcome(): void
    {
        $commercial = $this->makeCommercial();
        $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial);

        foreach (['OUI', 'NON', 'BV'] as $index => $outcome) {
            $freshClient = $client->fresh();
            $freshReservation = $index === 0 ? $reservation->fresh() : null;

            $result = $this->workflow->apply(
                $freshClient,
                $freshReservation,
                $outcome,
                ['note' => '   '], // note vide / blanche
                $commercial
            );

            $this->assertIsString($result['message']);
        }

        $this->assertSame(3, CallOutcome::count());
        $this->assertSame(0, CallOutcome::whereNotNull('note')->count());
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
            'status' => 'UNAVAILABLE_TEMP',
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
            'status' => 'UNAVAILABLE_TEMP',
            'returned_at' => now()->subMinute(),
        ]);
        $stillBlocked = $this->makeClient([
            'status' => 'UNAVAILABLE_TEMP',
            'returned_at' => now()->addDays(10),
        ]);

        $this->artisan('clients:reactivate')->assertExitCode(0);

        $expired->refresh();
        $stillBlocked->refresh();

        $this->assertSame('AVAILABLE', $expired->status);
        $this->assertNull($expired->returned_at);
        $this->assertSame('UNAVAILABLE_TEMP', $stillBlocked->status);
        $this->assertNotNull($stillBlocked->returned_at);
    }

    public function test_process_timeouts_command_handles_expired_recall_without_deleting_reservation(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => 'BV',
            'bv_count' => 0,
            'recall_at' => now()->subMinutes(5),
        ]);
        $future = $this->makeClient(['status' => 'RESERVED']);
        $futureReservation = $this->makeReservation($future, $commercial, [
            'status' => 'BV',
            'recall_at' => now()->addDay(),
        ]);

        $this->artisan('clients:process-timeouts')->assertExitCode(0);

        $this->assertSame(1, $reservation->fresh()->bv_count);
        $this->assertNull($reservation->fresh()->recall_at);
        $this->assertSame('RESERVED', $client->fresh()->status);
        $this->assertNotNull($futureReservation->fresh()->recall_at, 'Un rappel futur ne doit pas être traité.');
        $this->assertSame(2, Reservation::count());
    }

    // ------------------------------------------------- Aucune expiration de résa

    public function test_reservations_table_has_no_expires_at_column(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('reservations', 'expires_at'),
            'expires_at a été supprimée (plus aucune expiration de réservation).'
        );
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('clients', 'returned_at'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('clients', 'blocked_until'));
        $this->assertFalse(\method_exists(Reservation::class, 'pendingFor'));
    }
}
