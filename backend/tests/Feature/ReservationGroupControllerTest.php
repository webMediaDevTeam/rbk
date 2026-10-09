<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tableau « Mes listes » (ReservationGroupController) : listage avec
 * compteurs par statut, détail d'une liste, renommage, puis création de
 * lot (`POST clients/reserver`) : capacité, garde « traitement en cours »
 * et clients éligibles.
 */
class ReservationGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    private ?Enterprise $enterprise = null;

    private function makeCommercial(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));

        // Périmètre « source » de la Grande liste commerciale : l'employé est
        // rattaché à une entreprise dont la source vaut celle des clients
        // fixtures (`Client::DEFAULT_SOURCE`) — sans entreprise (ou sans
        // source), `GET /clients` est **vide** (verrou serveur, voir
        // GrandeListeSourceFilterTest).
        Employee::create([
            'user_id' => $user->id,
            'enterprise_id' => $this->sharedEnterprise()->id,
            'first_name' => 'Jean',
            'last_name' => 'Test',
        ]);

        return $user;
    }

    private function sharedEnterprise(): Enterprise
    {
        return $this->enterprise ??= Enterprise::create([
            'name' => 'Entreprise test',
            'status' => 'ACTIVE',
            'source' => Client::DEFAULT_SOURCE,
        ]);
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

    private function makeGroup(User $commercial, array $attrs = []): ReservationGroup
    {
        return ReservationGroup::create(array_merge([
            'comercial_id' => $commercial->id,
            'name' => 'Liste de test',
        ], $attrs));
    }

    // ------------------------------------------------------------- Listage index

    public function test_index_returns_only_own_groups_with_status_counters_and_comercial(): void
    {
        $commercial = $this->makeCommercial(['first_name' => 'Jean', 'last_name' => 'Tremblay']);
        $other = $this->makeCommercial();

        $group = $this->makeGroup($commercial, ['total' => 300, 'reserved_count' => 7]);
        $otherGroup = $this->makeGroup($other, ['name' => 'Liste d\'un autre employé']);

        // 7 réservations : 2 en attente (restant), 5 traitées
        // (1 YES / 2 NO dont 1 client blacklisté / 1 BV / 1 CALL_BACK).
        $statuses = [
            Reservation::STATUS_PENDING,
            Reservation::STATUS_PENDING,
            Reservation::STATUS_YES,
            Reservation::STATUS_NO,
            Reservation::STATUS_NO,
            Reservation::STATUS_BV_VOICEMAIL,
            Reservation::STATUS_CALL_BACK,
        ];

        foreach ($statuses as $index => $status) {
            $attrs = $index === 4
                ? ['status' => 'BLACKLISTED', 'is_blacklisted' => true]
                : ['status' => 'RESERVED'];

            $this->makeReservation($this->makeClient($attrs), $commercial, [
                'status' => $status,
                'reservation_group_id' => $group->id,
            ]);
        }

        // Réservation du connecté mais hors liste : non comptée.
        $this->makeReservation($this->makeClient(['status' => 'RESERVED']), $commercial);

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

        $this->assertSame(7, $row['clients_count'], 'Clients = réservations de la liste.');
        $this->assertSame(5, $row['traites_count'], 'Traités = YES + NO + BV + CALL_BACK.');
        $this->assertSame(1, $row['oui_count']);
        $this->assertSame(2, $row['non_count']);
        $this->assertSame(1, $row['bv_count']);
        $this->assertSame(1, $row['injoinable_count']);
        $this->assertSame(2, $row['restant_count'], 'Restant = encore PENDING.');
        $this->assertSame(1, $row['blacklist_count'], 'Colonne Blacklist = clients blacklistés de la liste.');
        $this->assertSame(
            $row['traites_count'] + $row['restant_count'],
            $row['clients_count'],
            'Traités + Restant doit retomber sur le nombre de clients.'
        );

        // Employé préchargé : relation `comercial` (jamais recalculée côté client).
        $this->assertSame($commercial->id, $row['comercial']['id']);
        $this->assertSame('Jean', $row['comercial']['first_name']);
        $this->assertSame('Tremblay', $row['comercial']['last_name']);
        $this->assertSame($commercial->email, $row['comercial']['email']);
        $this->assertSame('Jean Tremblay', $row['employe']);
    }

    public function test_index_caps_per_page_at_300(): void
    {
        $commercial = $this->makeCommercial();
        $this->makeGroup($commercial);

        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/reservation-groups?per_page=1000')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 300);
    }

    // ------------------------------------------------------------------ Détail show

    public function test_show_returns_own_group_with_reservations_and_clients(): void
    {
        $commercial = $this->makeCommercial();
        $group = $this->makeGroup($commercial, ['name' => 'Liste détail', 'total' => 50]);

        $client = $this->makeClient(['status' => 'RESERVED', 'name' => 'Bâtiments Ltee']);
        $reservation = $this->makeReservation($client, $commercial, [
            'status' => Reservation::STATUS_CALL_BACK,
            'reservation_group_id' => $group->id,
        ]);
        $rappel = $this->makeRappel($reservation, now()->addDays(2));

        Sanctum::actingAs($commercial);

        $response = $this->getJson("/api/v1/reservation-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.group.id', $group->id)
            ->assertJsonPath('data.group.name', 'Liste détail')
            ->assertJsonPath('data.group.clients_count', 1)
            ->assertJsonPath('data.group.traites_count', 1)
            ->assertJsonPath('data.group.restant_count', 0)
            ->assertJsonPath('data.group.injoinable_count', 1);

        $rows = $response->json('data.reservations');
        $this->assertCount(1, $rows);
        $this->assertSame($reservation->id, $rows[0]['id']);
        $this->assertSame(Reservation::STATUS_CALL_BACK, $rows[0]['status']);
        $this->assertEqualsWithDelta(
            $rappel->reminder_date->timestamp,
            Carbon::parse($rows[0]['recall_at'])->timestamp,
            2
        );
        // Client préchargé (colonnes affichées de la liste).
        $this->assertSame($client->id, $rows[0]['client']['id']);
        $this->assertSame('Bâtiments Ltee', $rows[0]['client']['name']);
        $this->assertSame('RESERVED', $rows[0]['client']['status']);
    }

    public function test_show_returns_404_for_other_commercials_group_or_unknown_id(): void
    {
        $owner = $this->makeCommercial();
        $intruder = $this->makeCommercial();
        $foreign = $this->makeGroup($owner, ['name' => 'Liste secrète']);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/reservation-groups/{$foreign->id}")->assertStatus(404);
        $this->getJson('/api/v1/reservation-groups/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    // ---------------------------------------------------------------- Renommage

    public function test_owner_can_rename_group_with_name_validation(): void
    {
        $commercial = $this->makeCommercial();
        $group = $this->makeGroup($commercial, ['name' => 'Ancien nom']);

        Sanctum::actingAs($commercial);

        // Nom manquant puis nom vide : 422, rien n'est écrit.
        $this->patchJson("/api/v1/reservation-groups/{$group->id}")
            ->assertStatus(422);
        $this->patchJson("/api/v1/reservation-groups/{$group->id}", ['name' => ''])
            ->assertStatus(422);
        $this->assertSame('Ancien nom', $group->fresh()->name);

        $this->patchJson("/api/v1/reservation-groups/{$group->id}", ['name' => 'Nouveau nom'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Nouveau nom');

        $this->assertSame('Nouveau nom', $group->fresh()->name);
    }

    public function test_other_commercial_cannot_rename_group(): void
    {
        $owner = $this->makeCommercial();
        $intruder = $this->makeCommercial();
        $group = $this->makeGroup($owner, ['name' => 'Liste secrète']);

        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/reservation-groups/{$group->id}", ['name' => 'HACK'])
            ->assertStatus(403);

        $this->assertSame('Liste secrète', $group->fresh()->name);
    }

    public function test_rename_unknown_group_returns_404(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        $this->patchJson(
            '/api/v1/reservation-groups/00000000-0000-0000-0000-000000000000',
            ['name' => 'Inconnu']
        )->assertStatus(404);
    }

    // ---------------------------------------------------------- Réserver un lot

    public function test_reserver_rejects_count_outside_allowed_options(): void
    {
        $commercial = $this->makeCommercial();

        Sanctum::actingAs($commercial);

        // Seuls 50 / 80 / 100 / 120 sont acceptés : 10 est refusé.
        $this->postJson('/api/v1/clients/reserver', ['count' => 10])
            ->assertStatus(422);

        $this->assertDatabaseCount('reservation_groups', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_reserver_is_blocked_while_treatment_is_pending(): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient(['status' => 'RESERVED']);
        $this->makeReservation($client, $commercial); // PENDING

        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertStatus(409)
            ->assertJsonPath('error', 'unfinished_treatment');

        $this->assertDatabaseCount('reservation_groups', 0);
    }

    public function test_reserver_creates_group_and_pending_reservations_for_eligible_clients_only(): void
    {
        $commercial = $this->makeCommercial();

        // 3 clients éligibles seulement, pour un lot demandé de 50.
        $eligible = [
            $this->makeClient(['name' => 'Éligible un']),
            $this->makeClient(['name' => 'Éligible deux']),
            $this->makeClient(['name' => 'Éligible trois']),
        ];

        // Non éligibles : blacklisté, sans téléphone, déjà réservé, confirmé.
        $blacklisted = $this->makeClient(['status' => 'BLACKLISTED', 'is_blacklisted' => true]);
        $noPhone = $this->makeClient(['status' => 'AVAILABLE', 'phone' => null]);
        $reserved = $this->makeClient(['status' => 'RESERVED']);
        $confirmed = $this->makeClient(['status' => 'CONFIRMED']);

        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requested', 50)
            ->assertJsonPath('data.reserved', 3);

        $group = ReservationGroup::firstOrFail();
        $this->assertSame($commercial->id, $group->comercial_id);
        $this->assertEquals(50, $group->total);
        $this->assertEquals(3, $group->reserved_count);

        // Une réservation PENDING par client réservé, rattachée au groupe.
        $this->assertDatabaseCount('reservations', 3);
        $this->assertSame(
            3,
            Reservation::where('reservation_group_id', $group->id)
                ->where('status', Reservation::STATUS_PENDING)
                ->count()
        );

        foreach ($eligible as $client) {
            $this->assertSame('RESERVED', $client->fresh()->status);
        }

        foreach ([$blacklisted, $noPhone, $reserved, $confirmed] as $client) {
            $this->assertFalse(
                Reservation::where('client_id', $client->id)->exists(),
                'Les clients inéligibles ne sont jamais sélectionnés.'
            );
        }
        $this->assertSame('AVAILABLE', $noPhone->fresh()->status);
        $this->assertSame('BLACKLISTED', $blacklisted->fresh()->status);
        $this->assertSame('CONFIRMED', $confirmed->fresh()->status);

        // Compteur du header : le lot créé compte comme traitement en cours.
        $this->getJson('/api/v1/reservations/active-count')
            ->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.pending', 3)
            ->assertJsonPath('data.can_reserve', false);
    }

    public function test_reserver_never_exceeds_requested_count(): void
    {
        $commercial = $this->makeCommercial();

        // 55 éligibles pour un lot de 50 : la capacité demandée fait foi.
        foreach (range(1, 55) as $i) {
            $this->makeClient(['name' => "Prospect {$i}"]);
        }

        Sanctum::actingAs($commercial);

        $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertCreated()
            ->assertJsonPath('data.requested', 50)
            ->assertJsonPath('data.reserved', 50);

        $group = ReservationGroup::firstOrFail();
        $this->assertEquals(50, $group->reserved_count);
        $this->assertDatabaseCount('reservations', 50);
        $this->assertSame(
            50,
            Reservation::where('reservation_group_id', $group->id)
                ->where('status', Reservation::STATUS_PENDING)
                ->count()
        );

        // Les 5 restants ne sont pas touchés.
        $this->assertSame(5, Client::where('status', 'AVAILABLE')->count());

        $this->getJson('/api/v1/reservations/active-count')
            ->assertOk()
            ->assertJsonPath('data.count', 50)
            ->assertJsonPath('data.pending', 50)
            ->assertJsonPath('data.can_reserve', false);
    }
}
