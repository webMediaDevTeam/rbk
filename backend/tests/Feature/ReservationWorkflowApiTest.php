<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests des endpoints du workflow (docs/RULES.md §1, §9, §10).
 */
class ReservationWorkflowApiTest extends TestCase
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

    /** Rappel planifié (table `rappels`, plus les colonnes de la réservation). */
    private function makeRappel(Reservation $reservation, ?Carbon $at): ?Rappel
    {
        if ($at === null) {
            return null; // réservation sans rappel : hors page « Rappels »
        }

        return Rappel::create([
            'client_id' => $reservation->client_id,
            'comercial_id' => $reservation->comercial_id,
            'reservation_id' => $reservation->id,
            'reminder_date' => $at,
        ]);
    }

    // ------------------------------------------------------- Compteur actives

    public function test_active_count_returns_only_active_reservations_of_connected_commercial(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        $activeClient = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($activeClient, $commercial);

        // Réservation inactive (client passée en UNAVAILABLE) : non comptée
        $inactiveClient = $this->makeClient(['status' => 'UNAVAILABLE']);
        $this->makeReservation($inactiveClient, $commercial, ['status' => Reservation::STATUS_NO]);

        // Réservation d'un autre commercial : non comptée
        $otherClient = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($otherClient, $other);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/reservations/active-count')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 1);
    }

    /**
     * Garde « traitement en cours » (docs/RULES.md §7.1) : `can_reserve` ne
     * passe à `true` que lorsque **toutes** les réservations de l'employé sont
     * sorties de `PENDING` (« en attente ») — le bouton « Réserver » de la
     * page Prospects suit ce champ, et l'API refuse aussi (`409`).
     */
    public function test_can_reserve_is_true_only_when_no_reservation_is_pending(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        // Liste encore « en attente » → réserver bloqué (UI désactivée + 409).
        $this->getJson('/api/v1/reservations/active-count')
            ->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.can_reserve', false);

        $this->postJson('/api/v1/clients/reserver', ['count' => 100])
            ->assertStatus(409)
            ->assertJsonPath('error', 'unfinished_treatment');

        // Issue d'appel enregistrée → plus aucune « en attente » → ouvert.
        $reservation->update(['status' => Reservation::STATUS_YES]);

        $this->getJson('/api/v1/reservations/active-count')
            ->assertOk()
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.can_reserve', true);
    }

    /**
     * **Libérer la liste** (`POST reservations/release-pending`,
     * docs/RULES.md §7.1) : les prospects encore « en attente » redeviennent
     * `AVAILABLE` pour tout le monde — l'employé n'a pas à finir la liste
     * commencée. Les réservations déjà traitées et celles des autres
     * employés sont conservées.
     */
    public function test_release_pending_frees_untreated_clients_and_keeps_treated_ones(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        // Deux prospects « en attente », dont un avec rappel planifié.
        $pendingA = $this->makeClient(['status' => 'RESERVED', 'returned_at' => now()]);
        $reservationA = $this->makeReservation($pendingA, $commercial);
        $this->makeRappel($reservationA, now()->addDay());

        $pendingB = $this->makeClient(['status' => 'RESERVED']);
        $reservationB = $this->makeReservation($pendingB, $commercial);

        // Déjà traité (issue Oui) → conservé.
        $treated = $this->makeClient(['status' => 'CONFIRMED']);
        $treatedReservation = $this->makeReservation($treated, $commercial, [
            'status' => Reservation::STATUS_YES,
        ]);

        // Liste « en attente » d'un autre employé → intacte.
        $otherClient = $this->makeClient(['status' => 'RESERVED']);
        $otherReservation = $this->makeReservation($otherClient, $other);

        Sanctum::actingAs($commercial);

        // Tant que la liste est en attente, la réreservation est refusée.
        $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertStatus(409)
            ->assertJsonPath('error', 'unfinished_treatment');

        $this->postJson('/api/v1/reservations/release-pending')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('released', 2)
            ->assertJsonPath('pending', 0)
            ->assertJsonPath('can_reserve', true);

        // Prospects libérés : de nouveau disponibles et sans pointeur.
        foreach ([$pendingA, $pendingB] as $client) {
            $fresh = $client->fresh();
            $this->assertSame('AVAILABLE', $fresh->status);
            $this->assertNull($fresh->returned_at);
            $this->assertNull($fresh->current_reservation_id);
            $this->assertNull($fresh->current_comercial_id);
        }

        $this->assertDatabaseMissing('reservations', ['id' => $reservationA->id]);
        $this->assertDatabaseMissing('reservations', ['id' => $reservationB->id]);
        $this->assertDatabaseCount('rappels', 0);

        // Historisation de la libération.
        $this->assertSame(2, Note::where('type', Note::TYPE_RETURNED_TO_AVAILABLE)->count());

        // Traité + autres employés : intacts.
        $this->assertSame('CONFIRMED', $treated->fresh()->status);
        $this->assertNotNull(Reservation::find($treatedReservation->id));
        $this->assertSame('RESERVED', $otherClient->fresh()->status);
        $this->assertNotNull(Reservation::find($otherReservation->id));

        // Rien de plus à libérer : nouvel appel neutre, réreservation ouverte.
        $this->postJson('/api/v1/reservations/release-pending')
            ->assertOk()
            ->assertJsonPath('released', 0)
            ->assertJsonPath('pending', 0)
            ->assertJsonPath('can_reserve', true);

        $this->getJson('/api/v1/reservations/active-count')
            ->assertOk()
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.can_reserve', true);
    }

    // ------------------------------------------------------------- Outcome API

    public function test_outcome_requires_reservation_for_yes(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'YES'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Vous devez réserver ce client avant de changer son statut.');

        $this->assertSame('AVAILABLE', $client->fresh()->status);
    }

    public function test_outcome_yes_with_reservation_returns_confirmed_status(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'YES',
            'note' => 'très intéressé par le devis',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', 'CONFIRMED')
            ->assertJsonPath('data.blacklisted', false);

        $this->assertSame('CONFIRMED', $client->fresh()->status);
        $this->assertSame('YES', Reservation::first()->status);

        // L'issue est journalisée dans `notes` (table unique de l'historique).
        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'sender_id' => $commercial->id,
            'type' => 'YES',
            'description' => 'très intéressé par le devis',
        ]);
    }

    public function test_outcome_rejects_more_than_eight_words_in_note(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'BV',
            'note' => 'un deux trois quatre cinq six sept huit neuf',
        ])->assertStatus(422);

        $this->assertSame(0, Note::count());
    }

    public function test_outcome_rejects_unknown_status_value(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'BOITE_VOCALE'])
            ->assertStatus(422);
    }

    public function test_non_without_reservation_nor_history_is_forbidden(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'NO'])
            ->assertStatus(403);

        $this->assertSame('AVAILABLE', $client->fresh()->status);
    }

    // --------------------------------------------------- Création de réservation

    public function test_reservation_rejects_free_count_and_works_without_name(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        // Nombre libre (77) refusé : seuls 50 / 80 / 100 / 120 sont acceptés.
        $this->postJson('/api/v1/clients/reserver', ['count' => 77])
            ->assertStatus(422);

        $this->assertDatabaseCount('reservation_groups', 0);

        $this->postJson('/api/v1/clients/reserver', ['count' => 120, 'group_name' => 'Liste'])
            ->assertCreated()
            ->assertJsonPath('data.requested', 120);

        // Sans nom : le serveur génère un nom de groupe par défaut.
        $response = $this->postJson('/api/v1/clients/reserver', ['count' => 80])
            ->assertCreated()
            ->assertJsonPath('data.requested', 80);

        $groupId = $response->json('data.group.id');
        $this->assertStringStartsWith('Liste du ', ReservationGroup::find($groupId)->name);
    }

    // --------------------------------------------------- Bouton « Suite appel »

    public function test_my_reservation_is_null_unless_active_for_connected_commercial(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        // Cas 1 : réservation active du connecté -> my_reservation présent.
        $activeClient = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($activeClient, $commercial);

        Sanctum::actingAs($commercial);

        $this->getJson("/api/v1/clients/{$activeClient->id}")
            ->assertOk()
            ->assertJsonPath('data.client.my_reservation.id', fn ($id) => $id !== null);

        $active = $this->getJson("/api/v1/clients/{$activeClient->id}")->json('data.client.my_reservation');
        $this->assertNotNull($active, 'Une réservation active doit renvoyer my_reservation.');

        // Cas 2 : aucune réservation du connecté -> null (bouton masqué).
        $freeClient = $this->makeClient(['status' => 'AVAILABLE']);

        $this->getJson("/api/v1/clients/{$freeClient->id}")
            ->assertOk()
            ->assertJsonPath('data.client.my_reservation', null);

        // Cas 3 : réservation du connecté mais inactive (NO) -> null.
        $nonClient = $this->makeClient(['status' => 'UNAVAILABLE']);
        $this->makeReservation($nonClient, $commercial, ['status' => Reservation::STATUS_NO]);

        $this->getJson("/api/v1/clients/{$nonClient->id}")
            ->assertOk()
            ->assertJsonPath('data.client.my_reservation', null);

        // Cas 4 : réservation d'un autre commercial -> null.
        $otherClient = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($otherClient, $other);

        $this->getJson("/api/v1/clients/{$otherClient->id}")
            ->assertOk()
            ->assertJsonPath('data.client.my_reservation', null);
    }

    // --------------------------------------------------- Rename de groupe

    public function test_group_owner_can_rename_group(): void
    {
        $commercial = $this->makeCommercial();
        $group = ReservationGroup::create([
            'comercial_id' => $commercial->id,
            'name' => 'Ancien nom',
        ]);

        Sanctum::actingAs($commercial);

        $this->patchJson("/api/v1/reservation-groups/{$group->id}", ['name' => 'Nouveau nom'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom');

        $this->assertSame('Nouveau nom', $group->fresh()->name);
    }

    public function test_other_commercial_cannot_rename_group(): void
    {
        $owner = $this->makeCommercial();
        $intruder = $this->makeCommercial();
        $group = ReservationGroup::create([
            'comercial_id' => $owner->id,
            'name' => 'Liste secrète',
        ]);

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/reservation-groups/{$group->id}", ['name' => 'HACK'])
            ->assertStatus(403);

        $this->assertSame('Liste secrète', $group->fresh()->name);
    }

    public function test_admin_can_rename_group_and_commercial_cannot_access_admin_routes(): void
    {
        $commercial = $this->makeCommercial();
        $admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $group = ReservationGroup::create([
            'comercial_id' => $commercial->id,
            'name' => 'Avant admin',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/reservation-groups/{$group->id}", ['name' => 'Après admin'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Après admin');

        $this->assertSame('Après admin', $group->fresh()->name);

        // Un commercial ne peut pas utiliser les routes admin (liste noire / unblock)
        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/liste-noire')->assertForbidden();
    }

    // ------------------------------------------------------------- Unic unblock

    public function test_admin_can_unblock_blacklisted_client_clearing_reservations(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $commercial = $this->makeCommercial();

        $client = $this->makeClient([
            'status' => 'BLACKLISTED',
            'is_blacklisted' => true,
            'returned_at' => now()->addDays(15),
        ]);
        $this->makeReservation($client, $commercial, ['status' => Reservation::STATUS_YES]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/liste-noire/{$client->id}/debloquer")
            ->assertOk()
            ->assertJsonPath('client.status', 'AVAILABLE')
            ->assertJsonPath('client.is_blacklisted', false);

        $client->refresh();

        $this->assertSame('AVAILABLE', $client->status);
        $this->assertFalse($client->is_blacklisted);
        $this->assertNull($client->returned_at);
        $this->assertSame(0, Reservation::count(), "L'unblock admin vide les réservations.");

        // La note de déblocage est émise par l'admin et le nomme.
        $note = Note::where('client_id', $client->id)
            ->where('type', Note::TYPE_RETURNED_TO_AVAILABLE)
            ->firstOrFail();
        $this->assertSame($admin->id, $note->sender_id);
        $this->assertStringContainsString('restauré AVAILABLE par', $note->description);
        $this->assertLessThanOrEqual(
            Note::MAX_NOTE_WORDS,
            count(preg_split('/\s+/u', trim($note->description), -1, PREG_SPLIT_NO_EMPTY)),
            'La description est une saisie humaine : 8 mots maximum.'
        );
    }

    public function test_commercial_cannot_unblock_client(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient([
            'status' => 'BLACKLISTED',
            'is_blacklisted' => true,
        ]);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/liste-noire/{$client->id}/debloquer")->assertForbidden();

        $client->refresh();
        $this->assertTrue($client->is_blacklisted);
        $this->assertSame(1, Reservation::count());
    }

    // ----------------------------------------------------------- Reminders API

    public function test_reminders_default_lists_only_call_back_with_scheduled_recall(): void
    {
        $commercial = $this->makeCommercial();

        $due = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDays(2)
        );
        $withRecall = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDays(1)
        );
        // Sans rappel planifié : hors page Rappels
        $noRecall = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => Reservation::STATUS_CALL_BACK]
        );
        $this->makeRappel($noRecall, null);
        // BV : géré par la page « Auto-rappels », pas par « Rappels »
        $bv = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->addDays(1)
        );
        // Réservé d'un autre commercial
        $other = $this->makeCommercial();
        $otherRappel = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $other,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDays(1)
        );

        Sanctum::actingAs($commercial);

        $response = $this->getJson('/api/v1/reminders')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSameSize($ids, $ids->unique());
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($due->id));
        $this->assertTrue($ids->contains($withRecall->id));
        $this->assertFalse(
            Rappel::where('reservation_id', $noRecall->id)->exists(),
            'Sans rappel planifié : hors page Rappels.'
        );
        $this->assertFalse($ids->contains($bv->id), 'Les BV vont sur la page Auto-rappels.');
        $this->assertFalse($ids->contains($otherRappel->id));

        // Le délai affiché est recalculé depuis reminder_date.
        $first = collect($response->json('data'))->firstWhere('id', $due->id);
        $this->assertSame('CALL_BACK', $first['status']);
        $this->assertSame(2, $first['recall_after']);
        $this->assertSame('JOUR', $first['recall_unit']);
    }

    public function test_reminders_type_bv_lists_only_bv_with_scheduled_recall(): void
    {
        $commercial = $this->makeCommercial();

        $bv = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->addDays(1)
        );
        $bvNoRecall = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => Reservation::STATUS_BV_VOICEMAIL]
        );
        $this->makeRappel($bvNoRecall, null);
        // CALL_BACK : appartient à la page « Rappels »
        $callBack = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDays(1)
        );
        $other = $this->makeCommercial();
        $otherBv = $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $other,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->addDays(1)
        );

        Sanctum::actingAs($commercial);

        $ids = collect($this->getJson('/api/v1/reminders?type=BV')->assertOk()->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($bv->id));
        $this->assertFalse($ids->contains($callBack->id));
        $this->assertFalse($ids->contains($otherBv->id));
        $this->assertFalse(
            Rappel::where('reservation_id', $bvNoRecall->id)->exists()
        );
    }

    public function test_reminders_rejects_unknown_type(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/reminders?type=WHATEVER')->assertStatus(422);
    }

    public function test_due_reminders_count_only_counts_expired_recalls_per_type(): void
    {
        $commercial = $this->makeCommercial();

        $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->subMinute()
        );
        $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_CALL_BACK]
            ),
            now()->addDay()
        );
        $this->makeRappel(
            $this->makeReservation(
                $this->makeClient(['status' => 'RESERVED']),
                $commercial,
                ['status' => Reservation::STATUS_BV_VOICEMAIL]
            ),
            now()->subMinute()
        );

        Sanctum::actingAs($commercial);

        // Défaut (page Rappels) : uniquement le CALL_BACK échu.
        $this->getJson('/api/v1/reminders/count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        // Page Auto-rappels : uniquement le BV échu.
        $this->getJson('/api/v1/reminders/count?type=BV')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->getJson('/api/v1/reminders/count?type=CALL_BACK')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_reminder_row_is_flagged_stale_when_a_newer_suivi_exists(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_CALL_BACK,
        ]);
        $rappel = $this->makeRappel($reservation, now()->addDay());

        Sanctum::actingAs($commercial);

        $row = collect($this->getJson('/api/v1/reminders')->assertOk()->json('data'))
            ->firstWhere('id', $rappel->id);

        // Colonnes « Prospects » + drapeaux d'obsolescence.
        $this->assertArrayHasKey('licence_number', $row);
        $this->assertArrayHasKey('respondents', $row);
        $this->assertArrayHasKey('display_status', $row);
        $this->assertArrayHasKey('has_newer_suivi', $row);
        $this->assertFalse($row['has_newer_suivi'], 'Aucun suivi après le rappel.');
        $this->assertFalse($row['status_changed']);
        $this->assertNull($row['done_at']);

        // Un suivi créé APRÈS le rappel : la ligne devient obsolète et le
        // bouton « Voir » sera masqué côté client.
        Carbon::setTestNow(now()->addMinutes(5));
        Note::create([
            'client_id' => $client->id,
            'sender_id' => $commercial->id,
            'type' => Note::TYPE_NOTE,
            'description' => 'Suivi refait après le rappel',
        ]);
        Carbon::setTestNow();

        $row = collect($this->getJson('/api/v1/reminders')->assertOk()->json('data'))
            ->firstWhere('id', $rappel->id);

        $this->assertTrue($row['has_newer_suivi'], 'Un suivi plus récent rend la ligne obsolète.');
        $this->assertFalse($row['status_changed'], 'Le statut de réservation, lui, n\'a pas bougé.');
    }

    // ------------------------------------------ Recall custom (CALL_BACK)

    public function test_outcome_call_back_requires_custom_recall_at(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'CALL_BACK'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'La date et l\'heure du rappel sont obligatoires pour un client injoignable.'
            );

        $this->assertSame('RESERVED', $client->fresh()->status);
        $this->assertSame(0, Note::count());
    }

    public function test_outcome_call_back_rejects_past_recall_at(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'CALL_BACK',
            'recall_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'La date de rappel doit être dans le futur.');

        $this->assertSame(0, Note::count());
    }

    public function test_outcome_call_back_schedules_custom_recall_datetime(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        // +5h01 : l'arrondi du délai reste à 5 heures même si la requête
        // prend quelques secondes.
        $recallAt = now()->addHours(5)->addMinute();

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'CALL_BACK',
            'recall_at' => $recallAt->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', 'RESERVED');

        $reservation = Reservation::first();
        $this->assertSame('CALL_BACK', $reservation->status);

        // Le rappel vit dans sa propre table ; le délai est recalculé.
        $rappel = Rappel::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertEqualsWithDelta($recallAt->timestamp, $rappel->reminder_date->timestamp, 5);
        $this->assertSame([5, 'HEURE'], $rappel->delay());
        $this->assertSame(1, $reservation->injoinable_count);
        $this->assertSame(0, $reservation->bv_count);
    }

    // ------------------------------------------------------------ Search F-22

    public function test_commercial_search_covers_enterprise_and_category_without_date_filter(): void
    {
        $commercial = $this->makeCommercial();

        $target = $this->makeClient([
            'status' => 'AVAILABLE',
            'name' => 'Bâtiments Ltee', 'enterprise_name' => 'Groupe Construction XYZ',
            'categories' => ['Résidentiel'],
        ]);
        $other = $this->makeClient([
            'status' => 'AVAILABLE',
            'name' => 'Autre Inc', 'enterprise_name' => 'Autre Groupe',
            'categories' => ['Industriel'],
        ]);

        Sanctum::actingAs($commercial);

        // Recherche par nom d'entreprise
        $searchResponse = $this->getJson('/api/v1/clients?search='.urlencode('Groupe Construction'))
            ->assertOk()
            ->assertJsonPath('data.clients.0.id', $target->id);

        $searchIds = collect($searchResponse->json('data.clients'))->pluck('id');
        $this->assertTrue($searchIds->contains($target->id), 'Le nom d\'entreprise doit être recherché.');
        $this->assertFalse($searchIds->contains($other->id), 'Seul le client ciblé doit matcher.');

        // Recherche par catégorie
        $categoryIds = collect(
            $this->getJson('/api/v1/clients?search='.urlencode('Résidentiel'))
                ->assertOk()
                ->json('data.clients')
        )->pluck('id');

        $this->assertTrue($categoryIds->contains($target->id), 'La catégorie doit être recherchée.');
        $this->assertFalse($categoryIds->contains($other->id), 'Seul le client de la catégorie doit matcher.');

        // Aucun filtre de date : date_from est ignoré, un client plus ancien reste listé
        $old = $this->makeClient(['status' => 'AVAILABLE']);
        \DB::table('clients')->where('id', $old->id)->update(['created_at' => now()->subDays(10)]);

        $dateIds = collect($this->getJson('/api/v1/clients?date_from='.now()->toDateString())->json('data.clients'))
            ->pluck('id');

        $this->assertTrue($dateIds->contains($target->id));
        $this->assertTrue($dateIds->contains($old->id), 'date_from ne doit plus filtrer sur created_at.');
    }

    // ------------------------------------ Tableau « Mes listes » (compteurs)

    public function test_group_list_returns_status_counts_and_employee_via_join(): void
    {
        $commercial = $this->makeCommercial(['first_name' => 'Jean', 'last_name' => 'Tremblay']);
        $other = $this->makeCommercial();

        $group = ReservationGroup::create([
            'comercial_id' => $commercial->id,
            'name' => 'Liste stats',
            'total' => 300,
            'reserved_count' => 6,
        ]);
        $otherGroup = ReservationGroup::create([
            'comercial_id' => $other->id,
            'name' => 'Liste d\'un autre employé',
            'total' => 200,
        ]);

        // 6 clients : 2 en attente (restant), 4 traités (1 chacun YES/NO/BV/CALL_BACK)
        foreach ([
            Reservation::STATUS_PENDING,
            Reservation::STATUS_PENDING,
            Reservation::STATUS_YES,
            Reservation::STATUS_NO,
            Reservation::STATUS_BV_VOICEMAIL,
            Reservation::STATUS_CALL_BACK,
        ] as $status) {
            $this->makeReservation($this->makeClient(['status' => 'RESERVED']), $commercial, [
                'status' => $status,
                'reservation_group_id' => $group->id,
            ]);
        }

        // Réservation du connecté mais hors groupe : non comptée.
        $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => Reservation::STATUS_YES]
        );

        // Liste d'un autre employé : non listée pour le connecté.
        $this->makeReservation($this->makeClient(['status' => 'RESERVED']), $other, [
            'status' => Reservation::STATUS_YES,
            'reservation_group_id' => $otherGroup->id,
        ]);

        Sanctum::actingAs($commercial);

        $groups = $this->getJson('/api/v1/reservation-groups')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.groups');

        $ids = collect($groups)->pluck('id');
        $this->assertTrue($ids->contains($group->id));
        $this->assertFalse($ids->contains($otherGroup->id), 'Seules les listes du connecté sont retournées.');

        $row = collect($groups)->firstWhere('id', $group->id);

        $this->assertSame(6, $row['clients_count'], 'Clients = réservations de la liste.');
        $this->assertSame(4, $row['traites_count'], 'Traités = YES + NO + BV + CALL_BACK.');
        $this->assertSame(1, $row['oui_count']);
        $this->assertSame(1, $row['non_count']);
        $this->assertSame(1, $row['bv_count']);
        $this->assertSame(1, $row['injoinable_count']);
        $this->assertSame(2, $row['restant_count'], 'Restant = encore PENDING.');
        $this->assertSame(
            ($row['traites_count'] ?? 0) + ($row['restant_count'] ?? 0),
            $row['clients_count'],
            'Traités + Restant doit retomber sur le nombre de clients.'
        );

        // Employé et date de création : jointure automatique côté serveur.
        $this->assertSame('Jean Tremblay', $row['employe']);
        $this->assertNotNull($row['created_at']);
    }

    public function test_group_list_employee_falls_back_to_email_without_names(): void
    {
        $commercial = $this->makeCommercial(['email' => 'sansnom@rbk.local']);

        ReservationGroup::create([
            'comercial_id' => $commercial->id,
            'name' => 'Liste sans nom d\'employé',
        ]);

        Sanctum::actingAs($commercial);

        $row = collect($this->getJson('/api/v1/reservation-groups')->assertOk()->json('data.groups'))
            ->firstWhere('name', 'Liste sans nom d\'employé');

        $this->assertNotNull($row);
        $this->assertSame('sansnom@rbk.local', $row['employe']);
        $this->assertSame(0, $row['clients_count']);
        $this->assertSame(0, $row['traites_count']);
        $this->assertSame(0, $row['restant_count']);
    }

    // -------------------------------------------- Reminders « terminer » (done)

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

    public function test_mark_reminder_done_removes_it_from_lists_and_writes_history_note(): void
    {
        $commercial = $this->makeCommercial();
        $rappel = $this->makeDueCallBackRappel($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/reminders/{$rappel->id}/done", ['note' => 'Rappel fait jeudi matin'])
            ->assertOk()
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
        $note = Note::where('client_id', $rappel->client_id)->first();
        $this->assertNotNull($note);
        $this->assertSame(Note::TYPE_NOTE, $note->type);
        $this->assertSame('Rappel fait jeudi matin', $note->description);
        $this->assertSame($commercial->id, $note->sender_id);
    }

    public function test_mark_reminder_done_works_without_note_and_cannot_run_twice(): void
    {
        $commercial = $this->makeCommercial();
        $rappel = $this->makeDueCallBackRappel($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/reminders/{$rappel->id}/done")->assertOk();

        $this->assertNull($rappel->fresh()->done_note, 'Note facultative.');
        $this->assertSame(0, Note::count(), 'Sans note, rien dans l\'historique.');

        // Déjà terminé : introuvable pour la suite.
        $this->postJson("/api/v1/reminders/{$rappel->id}/done")->assertStatus(404);
    }

    public function test_mark_reminder_done_is_limited_to_owner_and_to_eight_words(): void
    {
        $commercial = $this->makeCommercial();
        $rappel = $this->makeDueCallBackRappel($commercial);

        // Un autre employé ne touche pas au rappel d'autrui.
        Sanctum::actingAs($this->makeCommercial());
        $this->postJson("/api/v1/reminders/{$rappel->id}/done")->assertStatus(404);
        $this->assertNull($rappel->fresh()->done_at);

        // Plus de 8 mots : refusé, le rappel reste en attente.
        Sanctum::actingAs($commercial);
        $this->postJson("/api/v1/reminders/{$rappel->id}/done", [
            'note' => 'un deux trois quatre cinq six sept huit neuf',
        ])->assertStatus(422);

        $this->assertNull($rappel->fresh()->done_at);
        $this->assertSame(0, Note::count());
    }
}
