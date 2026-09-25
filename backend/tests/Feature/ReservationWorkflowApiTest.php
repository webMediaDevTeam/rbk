<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // ------------------------------------------------------- Compteur actives

    public function test_active_count_returns_only_active_reservations_of_connected_commercial(): void
    {
        $commercial = $this->makeCommercial();
        $other = $this->makeCommercial();

        $activeClient = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($activeClient, $commercial);

        // Réservation inactive (client passée en UNAVAILABLE_TEMP) : non comptée
        $inactiveClient = $this->makeClient(['status' => 'UNAVAILABLE_TEMP']);
        $this->makeReservation($inactiveClient, $commercial, ['status' => 'NON']);

        // Réservation d'un autre commercial : non comptée
        $otherClient = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($otherClient, $other);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/reservations/active-count')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 1);
    }

    // ------------------------------------------------------------- Outcome API

    public function test_outcome_requires_reservation_for_oui(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'AVAILABLE']);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'OUI'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Vous devez réserver ce client avant de changer son statut.');

        $this->assertSame('AVAILABLE', $client->fresh()->status);
    }

    public function test_outcome_oui_with_reservation_returns_success_status(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'OUI',
            'note' => 'très intéressé par le devis',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', 'SUCCESS')
            ->assertJsonPath('data.blacklisted', false);

        $this->assertSame('SUCCESS', $client->fresh()->status);
        $this->assertSame('OUI', Reservation::first()->status);
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

        $this->assertSame(0, \App\Models\CallOutcome::count());
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

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'NON'])
            ->assertStatus(403);

        $this->assertSame('AVAILABLE', $client->fresh()->status);
    }

    // --------------------------------------------------- Création de réservation

    public function test_reservation_rejects_free_count_and_works_without_name(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        // Nombre libre (50) refusé : seuls 200 / 250 / 300 sont acceptés.
        $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertStatus(422);

        $this->assertDatabaseCount('reservation_groups', 0);

        $this->postJson('/api/v1/clients/reserver', ['count' => 300, 'group_name' => 'Liste'])
            ->assertOk()
            ->assertJsonPath('data.requested', 300);

        // Sans nom : le serveur génère un nom de groupe par défaut.
        $response = $this->postJson('/api/v1/clients/reserver', ['count' => 250])
            ->assertOk()
            ->assertJsonPath('data.requested', 250);

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

        // Cas 3 : réservation du connecté mais inactive (NON) -> null.
        $nonClient = $this->makeClient(['status' => 'UNAVAILABLE_TEMP']);
        $this->makeReservation($nonClient, $commercial, ['status' => 'NON']);

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
        $this->makeReservation($client, $commercial, ['status' => 'OUI']);

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

        $this->assertDatabaseHas('call_outcomes', [
            'client_id' => $client->id,
            'outcome' => 'UNBLACKLIST',
        ]);
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

    public function test_reminders_default_lists_only_injoinable_with_scheduled_recall(): void
    {
        $commercial = $this->makeCommercial();

        $due = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'INJOINABLE', 'recall_at' => now()->addDays(2)]
        );
        $withRecall = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'INJOINABLE', 'recall_at' => now()->addDays(1)]
        );
        // Sans rappel planifié : hors page Rappels
        $noRecall = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'INJOINABLE', 'recall_at' => null]
        );
        // BV : géré par la page « Auto-rappels », pas par « Rappels »
        $bv = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'BV', 'recall_at' => now()->addDays(1)]
        );
        // Réservé d'un autre commercial
        $other = $this->makeCommercial();
        $otherReservation = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $other,
            ['status' => 'INJOINABLE', 'recall_at' => now()->addDays(1)]
        );

        Sanctum::actingAs($commercial);

        $response = $this->getJson('/api/v1/reminders')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSameSize($ids, $ids->unique());
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($due->id));
        $this->assertTrue($ids->contains($withRecall->id));
        $this->assertFalse($ids->contains($noRecall->id));
        $this->assertFalse($ids->contains($bv->id), 'Les BV vont sur la page Auto-rappels.');
        $this->assertFalse($ids->contains($otherReservation->id));
    }

    public function test_reminders_type_bv_lists_only_bv_with_scheduled_recall(): void
    {
        $commercial = $this->makeCommercial();

        $bv = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'BV', 'recall_at' => now()->addDays(1)]
        );
        $bvNoRecall = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'BV', 'recall_at' => null]
        );
        // INJOINABLE : appartient à la page « Rappels »
        $injoinable = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'INJOINABLE', 'recall_at' => now()->addDays(1)]
        );
        $other = $this->makeCommercial();
        $otherBv = $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $other,
            ['status' => 'BV', 'recall_at' => now()->addDays(1)]
        );

        Sanctum::actingAs($commercial);

        $ids = collect($this->getJson('/api/v1/reminders?type=BV')->assertOk()->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($bv->id));
        $this->assertFalse($ids->contains($bvNoRecall->id));
        $this->assertFalse($ids->contains($injoinable->id));
        $this->assertFalse($ids->contains($otherBv->id));
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

        $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'INJOINABLE', 'recall_at' => now()->subMinute()]
        );
        $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'INJOINABLE', 'recall_at' => now()->addDay()]
        );
        $this->makeReservation(
            $this->makeClient(['status' => 'RESERVED']),
            $commercial,
            ['status' => 'BV', 'recall_at' => now()->subMinute()]
        );

        Sanctum::actingAs($commercial);

        // Défaut (page Rappels) : uniquement l'INJOINABLE échu.
        $this->getJson('/api/v1/reminders/count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        // Page Auto-rappels : uniquement le BV échu.
        $this->getJson('/api/v1/reminders/count?type=BV')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->getJson('/api/v1/reminders/count?type=INJOINABLE')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    // ------------------------------------------ Recall custom (INJOINABLE)

    public function test_outcome_injoinable_requires_custom_recall_at(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => 'INJOINABLE'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'La date et l\'heure du rappel sont obligatoires pour un client injoignable.'
            );

        $this->assertSame('RESERVED', $client->fresh()->status);
        $this->assertSame(0, \App\Models\CallOutcome::count());
    }

    public function test_outcome_injoinable_rejects_past_recall_at(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'INJOINABLE',
            'recall_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'La date de rappel doit être dans le futur.');

        $this->assertSame(0, \App\Models\CallOutcome::count());
    }

    public function test_outcome_injoinable_schedules_custom_recall_datetime(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial);

        // +5h01 : le calcul « floor » des minutes reste à 5 heures même si la
        // requête prend quelques secondes.
        $recallAt = now()->addHours(5)->addMinute();

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => 'INJOINABLE',
            'recall_at' => $recallAt->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', 'RESERVED');

        $reservation = Reservation::first();
        $this->assertSame('INJOINABLE', $reservation->status);
        $this->assertEqualsWithDelta($recallAt->timestamp, $reservation->recall_at->timestamp, 5);
        $this->assertSame(5, $reservation->rappel_after);
        $this->assertSame('HEURE', $reservation->rappel_type);
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

        // 6 clients : 2 en attente (restant), 4 traités (1 chacun OUI/NON/BV/INJOINABLE)
        foreach (['EN_ATTENT', 'EN_ATTENT', 'OUI', 'NON', 'BV', 'INJOINABLE'] as $status) {
            $this->makeReservation($this->makeClient(['status' => 'RESERVED']), $commercial, [
                'status' => $status,
                'reservation_group_id' => $group->id,
            ]);
        }

        // Réservation du connecté mais hors groupe : non comptée.
        $this->makeReservation($this->makeClient(['status' => 'RESERVED']), $commercial, ['status' => 'OUI']);

        // Liste d'un autre employé : non listée pour le connecté.
        $this->makeReservation($this->makeClient(['status' => 'RESERVED']), $other, [
            'status' => 'OUI',
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
        $this->assertSame(4, $row['traites_count'], 'Traités = OUI + NON + BV + INJOINABLE.');
        $this->assertSame(1, $row['oui_count']);
        $this->assertSame(1, $row['non_count']);
        $this->assertSame(1, $row['bv_count']);
        $this->assertSame(1, $row['injoinable_count']);
        $this->assertSame(2, $row['restant_count'], 'Restant = encore EN_ATTENT.');
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
}
