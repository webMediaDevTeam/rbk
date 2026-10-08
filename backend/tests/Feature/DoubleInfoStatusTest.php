<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issues « Double » / « Info » (docs/RULES.md §3) : nouveaux statuts **client**
 * et **réservation**, au régime « Réservé » — le prospect reste tenu par
 * l'employé qui a enregistré l'issue :
 *
 *  - `Client::STATUS_DOUBLE` / `STATUS_INFO` + `Reservation::STATUS_DOUBLE` /
 *    `STATUS_INFO` (enum du modèle, compteurs « traité » inclus) ;
 *  - visibilité : dans la Grande liste (`GET clients`) ces clients ne sont
 *    rendus **qu'à leur titulaire** (les autres employés ne les voient pas),
 *    l'admin garde sa liste complète ;
 *  - jamais réservables une seconde fois (`POST clients/reserver` les exclut) ;
 *  - fiche détail : le titulaire garde son `my_reservation` (bouton « Suite
 *    appel » actif) et son numéro ; un collègue n'a ni l'un ni l'autre.
 */
class DoubleInfoStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeCommercial(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
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

    /** Client réservé par `$commercial`, réservation encore `PENDING`. */
    private function reservedClientFor(User $commercial): array
    {
        $client = $this->makeClient(['status' => Client::STATUS_RESERVED]);
        $reservation = $this->makeReservation($client, $commercial);

        return [$client, $reservation];
    }

    /**
     * Client **déjà** tenu par `$commercial` au statut DOUBLE / INFO :
     * réservation au statut correspondant + pointeur de réservation courante
     * à jour (le couple client/réservation que le workflow produit).
     */
    private function heldClientFor(User $commercial, string $clientStatus = Client::STATUS_DOUBLE): array
    {
        $reservationStatus = $clientStatus === Client::STATUS_INFO
            ? Reservation::STATUS_INFO
            : Reservation::STATUS_DOUBLE;

        $client = $this->makeClient(['status' => Client::STATUS_RESERVED]);
        $reservation = $this->makeReservation($client, $commercial, ['status' => $reservationStatus]);
        $client->update(['status' => $clientStatus]);

        return [$client, $reservation];
    }

    /** Ids rendus par une liste de prospects (`GET clients`). */
    private function listIds(string $uri, User $acting): array
    {
        Sanctum::actingAs($acting);

        $rows = $this->getJson($uri)->assertOk()->json('data.clients');

        return collect($rows)->pluck('id')->all();
    }

    // ---------------------------------------------------------- Workflow d'appel

    public static function heldOutcomes(): array
    {
        return [
            'double' => [Note::TYPE_DOUBLE, Client::STATUS_DOUBLE, Reservation::STATUS_DOUBLE],
            'info' => [Note::TYPE_INFO, Client::STATUS_INFO, Reservation::STATUS_INFO],
        ];
    }

    public static function heldOutcomeCodes(): array
    {
        return [
            'double' => [Note::TYPE_DOUBLE],
            'info' => [Note::TYPE_INFO],
        ];
    }

    #[DataProvider('heldOutcomes')]
    public function test_held_outcome_keeps_the_client_with_its_employee(
        string $outcome,
        string $clientStatus,
        string $reservationStatus,
    ): void {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => $outcome,
            'note' => 'prise par un autre intervenant',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_status', $clientStatus)
            ->assertJsonPath('data.blacklisted', false);

        $fresh = $client->fresh();
        $this->assertSame($clientStatus, $fresh->status, 'Statut client DOUBLE / INFO.');
        $this->assertNull($fresh->returned_at, 'Prospect tenu : aucun compte à rebours.');
        $this->assertSame($reservationStatus, $reservation->fresh()->status, 'Réservation suivante.');

        // Le pointeur « réservation courante » ne bouge pas : même titulaire.
        $this->assertSame($reservation->id, $fresh->current_reservation_id);
        $this->assertSame($commercial->id, $fresh->current_comercial_id);

        // L'issue est journalisée dans `notes` comme les autres.
        $this->assertDatabaseHas('notes', [
            'client_id' => $client->id,
            'reservation_id' => $reservation->id,
            'sender_id' => $commercial->id,
            'type' => $outcome,
        ]);
    }

    public function test_held_outcome_cancels_the_pending_recall(): void
    {
        $commercial = $this->makeCommercial();
        [$client, $reservation] = $this->reservedClientFor($commercial);

        Rappel::create([
            'client_id' => $client->id,
            'comercial_id' => $commercial->id,
            'reservation_id' => $reservation->id,
            'reminder_date' => now()->addDays(3),
        ]);

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_DOUBLE,
        ])->assertOk();

        $this->assertSame(
            0,
            Rappel::where('reservation_id', $reservation->id)->count(),
            'L\'issue enregistrée remplace le rappel en attente.'
        );
    }

    #[DataProvider('heldOutcomeCodes')]
    public function test_held_outcome_is_refused_without_a_reservation(string $outcome): void
    {
        $commercial = $this->makeCommercial();
        $client = $this->makeClient();

        Sanctum::actingAs($commercial);

        $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => $outcome])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(Client::STATUS_AVAILABLE, $client->fresh()->status, 'Aucun changement.');
    }

    public function test_unknown_outcome_labels_are_rejected(): void
    {
        $commercial = $this->makeCommercial();
        [$client] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);

        foreach (['DOUBLEE', 'OUI', 'INFOBOX'] as $legacy) {
            $this->postJson("/api/v1/clients/{$client->id}/outcome", ['outcome' => $legacy])
                ->assertStatus(422)
                ->assertJsonValidationErrors('outcome');
        }

        $this->assertSame(Client::STATUS_RESERVED, $client->fresh()->status);
    }

    // ------------------------------------------------------------- Visibilité

    public function test_double_client_is_visible_in_the_grande_list_only_to_its_holder(): void
    {
        $holder = $this->makeCommercial();
        $colleague = $this->makeCommercial();
        [$client] = $this->heldClientFor($holder);

        $holderIds = $this->listIds('/api/v1/clients', $holder);
        $this->assertContains($client->id, $holderIds, 'Le titulaire voit son client « Double ».');

        $colleagueIds = $this->listIds('/api/v1/clients', $colleague);
        $this->assertNotContains($client->id, $colleagueIds, 'Un autre employé ne le voit pas.');

        // La Grande liste admin reste complète (admin only).
        $adminIds = $this->listIds('/api/v1/commercials/clients', $this->makeAdmin());
        $this->assertContains($client->id, $adminIds, 'L\'admin voit toutes les lignes.');
    }

    public function test_info_client_is_visible_in_the_grande_list_only_to_its_holder(): void
    {
        $holder = $this->makeCommercial();
        $colleague = $this->makeCommercial();
        [$client] = $this->heldClientFor($holder, Client::STATUS_INFO);

        $this->assertContains($client->id, $this->listIds('/api/v1/clients', $holder));
        $this->assertNotContains($client->id, $this->listIds('/api/v1/clients', $colleague));
    }

    public function test_admin_can_filter_the_grande_list_by_double_status(): void
    {
        $holder = $this->makeCommercial();
        [$client] = $this->heldClientFor($holder);
        $available = $this->makeClient(['name' => 'Autre prospect']);

        $ids = $this->listIds('/api/v1/commercials/clients?status=DOUBLE', $this->makeAdmin());

        $this->assertContains($client->id, $ids);
        $this->assertNotContains($available->id, $ids, 'Seul le prospect DOUBLE est rendu.');
    }

    public function test_a_double_client_is_never_reserved_a_second_time(): void
    {
        $holder = $this->makeCommercial();
        $colleague = $this->makeCommercial();
        [$client] = $this->heldClientFor($holder);
        $available = $this->makeClient(['name' => 'Dispo inc.']);

        Sanctum::actingAs($colleague);

        $response = $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertCreated();

        $ids = collect($response->json('data.clients'))->pluck('id');

        $this->assertNotContains($client->id, $ids->all(), '« Double » n\'entre jamais dans un lot.');
        $this->assertSame(1, Reservation::where('client_id', $client->id)->count());
        $this->assertSame(Client::STATUS_DOUBLE, $client->fresh()->status);

        $this->assertContains($available->id, $ids->all(), 'Le seul disponible est réservé.');
    }

    // ----------------------------------------------------------- Fiche détail

    public function test_only_the_holder_keeps_the_reservation_and_the_phone(): void
    {
        $holder = $this->makeCommercial();
        $colleague = $this->makeCommercial();
        [$client] = $this->heldClientFor($holder);

        Sanctum::actingAs($holder);
        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client.status', Client::STATUS_DOUBLE)
            ->assertJsonPath('data.client.my_reservation.status', Reservation::STATUS_DOUBLE)
            ->assertJsonPath('data.client.phone', '514-555-0100');

        Sanctum::actingAs($colleague);
        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client.my_reservation', null)
            ->assertJsonMissingPath('data.client.phone');
    }

    // ----------------------------------------------------------------- KPI

    public function test_overview_counts_held_clients_as_reserved_and_in_progress(): void
    {
        $commercial = $this->makeCommercial();
        [$client] = $this->reservedClientFor($commercial);

        Sanctum::actingAs($commercial);
        $this->postJson("/api/v1/clients/{$client->id}/outcome", [
            'outcome' => Note::TYPE_DOUBLE,
        ])->assertOk();

        Sanctum::actingAs($this->makeAdmin());
        $overview = $this->getJson('/api/v1/clients/overview')->assertOk()->json('data');

        $this->assertSame(1, $overview['reserved']['total'], 'DOUBLE compte comme « réservé ».');
        $this->assertSame(1, $overview['reserved']['processed'], 'Traité et toujours tenu.');
        $this->assertSame(1, $overview['processed']['total'], 'DOUBLE est une issue d\'appel.');
        $this->assertSame(1, $overview['processed']['in_progress']);
        $this->assertSame(0, $overview['processed']['success']);
    }

    // ------------------------------------------------- Barre de badges (§9)

    public function test_grande_list_badges_count_and_filter_double_and_info(): void
    {
        $holder = $this->makeCommercial();
        $admin = $this->makeAdmin();

        [$doubleClient, $doubleReservation] = $this->reservedClientFor($holder);
        [$infoClient] = $this->reservedClientFor($holder);
        $free = $this->makeClient(['name' => 'Libre inc.']);

        Sanctum::actingAs($holder);
        $this->postJson("/api/v1/clients/{$doubleClient->id}/outcome", [
            'outcome' => Note::TYPE_DOUBLE,
        ])->assertOk();
        $this->postJson("/api/v1/clients/{$infoClient->id}/outcome", [
            'outcome' => Note::TYPE_INFO,
        ])->assertOk();

        Sanctum::actingAs($admin);

        // Compteurs de la barre (`by_display_status` de `clients/overview`).
        $badges = $this->getJson('/api/v1/clients/overview')
            ->assertOk()
            ->json('data.by_display_status');

        $this->assertSame(1, $badges[Reservation::STATUS_DOUBLE], 'Badge « Double ».');
        $this->assertSame(1, $badges[Reservation::STATUS_INFO], 'Badge « Info ».');
        $this->assertSame(1, $badges[Client::STATUS_AVAILABLE], 'Badge « Libre ».');

        // Invariant compteur ⇄ lignes : le clic rend exactement le compteur
        // (les deux nouveaux badges filtrent la réservation courante).
        $this->assertSame(
            [$doubleClient->id],
            $this->listIds('/api/v1/commercials/clients?reservation_status=DOUBLE', $admin),
            'Le clic « Double » rend la ligne « Double ».'
        );
        $this->assertSame(
            [$infoClient->id],
            $this->listIds('/api/v1/commercials/clients?reservation_status=INFO', $admin),
            'Le clic « Info » rend la ligne « Info ».'
        );

        // Détail d'un employé : mêmes 10 compteurs sur **son** périmètre.
        $historyBadges = $this->getJson("/api/v1/commercials/{$holder->id}")
            ->assertOk()
            ->json('historique.badges.by_display_status');

        $this->assertSame(1, $historyBadges[Reservation::STATUS_DOUBLE]);
        $this->assertSame(1, $historyBadges[Reservation::STATUS_INFO]);

        // Le badge « Libre » (dimension client) reste exact lui aussi.
        $this->assertSame([$free->id], $this->listIds('/api/v1/commercials/clients?status=AVAILABLE', $admin));
        $this->assertSame(Reservation::STATUS_DOUBLE, $doubleReservation->fresh()->status);
    }
}
