<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CallWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endpoint `POST /api/v1/clients/{clientId}/outcome`
 * (docs/RULES.md §3) : seules les issues du modèle YES / NO / BV /
 * CALL_BACK sont acceptées ; les anciens libellés d'UI (OUI / NON /
 * INJOINABLE) sont refusés en 422.
 */
class OutcomeControllerTest extends TestCase
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
            'status' => Client::STATUS_AVAILABLE,
            // La liste commerciale exclut les prospects sans numéro.
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

    /** Client réservé par le connecté, prêt à recevoir une issue. */
    private function reservedClientFor(User $commercial): array
    {
        $client = $this->makeClient(['status' => Client::STATUS_RESERVED]);
        $reservation = $this->makeReservation($client, $commercial);

        return [$client, $reservation];
    }

    // ------------------------------------------------------- Transitions d'appel

    public function test_yes_transition_confirms_client_and_writes_event_note(): void
    {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_YES,
            'note' => 'très intéressé par le devis',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', Client::STATUS_CONFIRMED)
            ->assertJsonPath('data.blacklisted', false);

        $fresh = $client->fresh();
        $this->assertSame(Client::STATUS_CONFIRMED, $fresh->status);
        $this->assertNull($fresh->returned_at, 'CONFIRMED est définitif : plus de compte à rebours.');
        $this->assertSame(Reservation::STATUS_YES, $reservation->fresh()->status);

        // L'issue est journalisée dans `notes` (table unique de l'historique).
        $this->assertDatabaseCount('notes', 1);
        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'reservation_id' => $reservation->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_YES,
            'description' => 'très intéressé par le devis',
        ]);
    }

    public function test_no_transition_blocks_client_for_three_months(): void
    {
        $commercial = $this->makeCommercial();
        // 2e commercial actif : un seul NON ne déclenche pas l'auto-blacklist
        // (tous les employés actifs doivent avoir répondu NON pour cela).
        $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_NO,
            'note' => 'pas du tout intéressé',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', Client::STATUS_UNAVAILABLE)
            ->assertJsonPath('data.blacklisted', false);

        $fresh = $client->fresh();
        $this->assertSame(Client::STATUS_UNAVAILABLE, $fresh->status);
        $this->assertNotNull($fresh->returned_at);
        $this->assertEqualsWithDelta(
            now()->addMonths(CallWorkflowService::NON_BLOCK_MONTHS)->timestamp,
            $fresh->returned_at->timestamp,
            5,
            'NO bloque le client pour NON_BLOCK_MONTHS mois.'
        );
        $this->assertSame(Reservation::STATUS_NO, $reservation->fresh()->status);

        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'reservation_id' => $reservation->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_NO,
        ]);
    }

    public function test_bv_keeps_client_reserved_and_schedules_rappel_in_three_days(): void
    {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_BV,
            'note' => 'laisser un message',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', Client::STATUS_RESERVED)
            ->assertJsonPath('data.blacklisted', false);

        $this->assertSame(Client::STATUS_RESERVED, $client->fresh()->status, 'Le BV laisse le client réservé.');

        $freshReservation = $reservation->fresh();
        $this->assertSame(Reservation::STATUS_BV_VOICEMAIL, $freshReservation->status);
        $this->assertSame(1, $freshReservation->bv_count);

        // Rappel automatique à 3 jours, rattaché à la réservation.
        $rappel = Rappel::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame($client->id, $rappel->client_id);
        $this->assertSame($commercial->id, $rappel->comercial_id);
        $this->assertEqualsWithDelta(
            now()->addDays(CallWorkflowService::RECALL_DAYS)->timestamp,
            $rappel->reminder_date->timestamp,
            5,
            'Le rappel BV tombe à RECALL_DAYS (3 jours).'
        );

        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'reservation_id' => $reservation->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_BV,
        ]);
    }

    // -------------------------------------------------- CALL_BACK (rappel choisi)

    public function test_call_back_without_recall_at_returns_422(): void
    {
        $commercial = $this->makeCommercial();
        [$client] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => Note::TYPE_CALL_BACK])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La date et l\'heure du rappel sont obligatoires pour un client injoignable.');

        $this->assertSame(Client::STATUS_RESERVED, $client->fresh()->status);
        $this->assertSame(0, Note::count(), 'Refus avant toute écriture.');
        $this->assertSame(0, Rappel::count());
    }

    public function test_call_back_with_past_recall_at_returns_422(): void
    {
        $commercial = $this->makeCommercial();
        [$client] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_CALL_BACK,
            'recall_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'La date de rappel doit être dans le futur.');

        $this->assertSame(0, Note::count());
        $this->assertSame(0, Rappel::count());
    }

    public function test_call_back_schedules_rappel_at_chosen_datetime(): void
    {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        // +5h01 : l'arrondi du délai reste à 5 heures même si la requête
        // prend quelques secondes.
        $recallAt = now()->addHours(5)->addMinute();

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_CALL_BACK,
            'recall_at' => $recallAt->toIso8601String(),
            'note' => 'numéro non attribué',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', Client::STATUS_RESERVED)
            ->assertJsonPath('data.blacklisted', false);

        $this->assertSame(Client::STATUS_RESERVED, $client->fresh()->status);

        $freshReservation = $reservation->fresh();
        $this->assertSame(Reservation::STATUS_CALL_BACK, $freshReservation->status);
        $this->assertSame(1, $freshReservation->injoinable_count);

        // Le rappel vit dans sa propre table, à la date saisie.
        $rappel = Rappel::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertEqualsWithDelta($recallAt->timestamp, $rappel->reminder_date->timestamp, 5);

        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'reservation_id' => $reservation->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_CALL_BACK,
        ]);
    }

    // -------------------------------------------------------------- Validation

    public function test_missing_and_legacy_outcome_values_are_rejected(): void
    {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $payloads = [
            [], // outcome absent
            ['outcome' => 'OUI'],
            ['outcome' => 'NON'],
            ['outcome' => 'INJOINABLE'],
            ['outcome' => 'FOO'],
        ];

        foreach ($payloads as $payload) {
            // Réponse de validation Laravel : `message` + `errors`, sans clé
            // `success` (celle-ci n'existe que sur les réponses du workflow).
            $this->postJson("/api/v1/clients/{$client->id}/outcome", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['outcome']);
        }

        // Refus avant toute écriture : ni note, ni changement de statut.
        $this->assertSame(0, Note::count());
        $this->assertSame(0, Rappel::count());
        $this->assertSame(Client::STATUS_RESERVED, $client->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    public function test_note_longer_than_eight_words_is_rejected_by_the_model_rule(): void
    {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_YES,
            'note' => 'un deux trois quatre cinq six sept huit neuf',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['description']);

        // La transaction du workflow est annulée : rien n'a bougé.
        $this->assertSame(0, Note::count());
        $this->assertSame(Client::STATUS_RESERVED, $client->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
    }

    // ------------------------------------------------------------ Garde d'état

    public function test_yes_bv_and_call_back_require_a_reservation_of_connected_commercial(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        // Client sans aucune réservation…
        $free = $this->makeClient(['status' => Client::STATUS_AVAILABLE]);
        // …et client réservé par un AUTRE commercial.
        $taken = $this->makeClient(['status' => Client::STATUS_RESERVED]);
        $otherReservation = $this->makeReservation($taken, $other);

        Sanctum::actingAs($commercial);

        $payloads = [
            ['outcome' => Note::TYPE_YES],
            ['outcome' => Note::TYPE_BV],
            ['outcome' => Note::TYPE_CALL_BACK, 'recall_at' => now()->addDay()->toIso8601String()],
        ];

        foreach ($payloads as $payload) {
            foreach ([$free, $taken] as $client) {
                $this->postJson("/api/v1/clients/{$client->id}/outcome", $payload)
                    ->assertStatus(422)
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('message', 'Vous devez réserver ce client avant de changer son statut.');
            }
        }

        $this->assertSame(Client::STATUS_AVAILABLE, $free->fresh()->status);
        $this->assertSame(Client::STATUS_RESERVED, $taken->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $otherReservation->fresh()->status);
        $this->assertSame(0, Note::count());
        $this->assertSame(0, Rappel::count());
    }

    public function test_no_without_reservation_nor_history_is_forbidden(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        $free = $this->makeClient(['status' => Client::STATUS_AVAILABLE]);
        $taken = $this->makeClient(['status' => Client::STATUS_RESERVED]);
        $otherReservation = $this->makeReservation($taken, $other);

        Sanctum::actingAs($commercial);

        foreach ([$free, $taken] as $client) {
            $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => Note::TYPE_NO])
                ->assertStatus(403)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Vous devez avoir une réservation ou un historique d\'appel pour ce client.');
        }

        $this->assertSame(Client::STATUS_AVAILABLE, $free->fresh()->status);
        $this->assertSame(Client::STATUS_RESERVED, $taken->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $otherReservation->fresh()->status);
        $this->assertSame(0, Note::count());
    }

    public function test_no_is_allowed_with_prior_call_history_but_no_reservation(): void
    {
        $commercial = $this->makeCommercial();
        // 2e commercial actif : évite l'auto-blacklist (tous ont répondu NON).
        $this->makeCommercial();
        $client = $this->makeClient(['status' => Client::STATUS_AVAILABLE]);

        // Historique d'appel du connecté sur ce client (aucune réservation).
        Note::create([
            'client_id' => $client->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_BV,
            'description' => 'laisser un message',
        ]);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => Note::TYPE_NO])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', Client::STATUS_UNAVAILABLE);

        $this->assertSame(Client::STATUS_UNAVAILABLE, $client->fresh()->status);
        $this->assertSame(1, Note::where('type', Note::TYPE_NO)->count());
    }

    // ------------------------------------------------------------------- Rôle

    public function test_non_commercial_role_is_forbidden_by_route_middleware(): void
    {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        foreach (['ADMIN', 'SUPER_ADMIN'] as $role) {
            $admin = User::factory()->create(['role' => $role, 'status' => 'ACTIVE']);

            Sanctum::actingAs($admin);

            $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => Note::TYPE_YES])
                ->assertForbidden()
                ->assertJsonPath('message', 'Accès non autorisé.');
        }

        $this->assertSame(Client::STATUS_RESERVED, $client->fresh()->status);
        $this->assertSame(Reservation::STATUS_PENDING, $reservation->fresh()->status);
        $this->assertSame(0, Note::count());
    }
}
