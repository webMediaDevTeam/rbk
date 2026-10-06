<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pointeur « réservation courante » du client
 * (`clients.current_reservation_id` / `current_comercial_id`) et les badges
 * de la colonne « Statut » (docs/RULES.md §2 et §9).
 *
 * Fil conducteur : **une seule valeur par ligne** — Disponible / Blacklist
 * (statut client) ou le statut de la réservation courante (Oui / Non / BV /
 * À rappeler / « - ») — et l'invariant *compteur du badge = lignes rendues*.
 */
class CurrentReservationStatusBadgesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
    }

    private function makeClient(array $attrs = []): Client
    {
        // Numéro présent par défaut : le seau dérivé « Sans téléphone »
        // (`phone` vide) est testé à part, en écrasant `phone`.
        return Client::create(array_merge([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    /** Client déjà appelé au moins une fois (historique d'appel). */
    private function called(array $attrs = [], string $noteType = Note::TYPE_YES): Client
    {
        $client = $this->makeClient($attrs);

        Note::create([
            'client_id' => $client->id,
            'sender_id' => $this->commercial->id,
            'type' => $noteType,
        ]);

        return $client;
    }

    /** Réservation + statut client associé (le workflow écrit les deux). */
    private function reserve(Client $client, string $reservationStatus, array $attrs = []): Reservation
    {
        $reservation = Reservation::create(array_merge([
            'client_id' => $client->id,
            'comercial_id' => $this->commercial->id,
            'status' => $reservationStatus,
        ], $attrs));

        $client->update(['status' => match ($reservationStatus) {
            Reservation::STATUS_YES => Client::STATUS_CONFIRMED,
            Reservation::STATUS_NO => Client::STATUS_UNAVAILABLE,
            default => Client::STATUS_RESERVED,
        }]);

        return $reservation;
    }

    /** Un client par valeur affichable dans la colonne « Statut ». */
    private function fixtureClients(): array
    {
        // Relisté : le statut client prime sur sa réservation « NO » passée.
        $available = $this->called(['status' => Client::STATUS_AVAILABLE], Note::TYPE_NO);

        $blacklisted = $this->called(['status' => Client::STATUS_AVAILABLE]);
        $this->reserve($blacklisted, Reservation::STATUS_YES);
        $blacklisted->update(['status' => Client::STATUS_BLACKLISTED, 'is_blacklisted' => true]);

        $oui = $this->called();
        $this->reserve($oui, Reservation::STATUS_YES);

        $non = $this->called(['status' => Client::STATUS_AVAILABLE], Note::TYPE_NO);
        $this->reserve($non, Reservation::STATUS_NO);

        $bv = $this->called(['status' => Client::STATUS_AVAILABLE], Note::TYPE_BV);
        $this->reserve($bv, Reservation::STATUS_BV_VOICEMAIL);

        $rappel = $this->called(['status' => Client::STATUS_AVAILABLE], Note::TYPE_CALL_BACK);
        $this->reserve($rappel, Reservation::STATUS_CALL_BACK);

        return compact('available', 'blacklisted', 'oui', 'non', 'bv', 'rappel');
    }

    /** Ids rendus par la liste admin (`GET commercials/clients`). */
    private function adminListIds(string $query): array
    {
        $rows = $this->getJson('/api/v1/commercials/clients?'.$query)
            ->assertOk()
            ->json('data.clients');

        return collect($rows)->pluck('id')->all();
    }

    /** Ids rendus par l'historique d'un employé (`GET commercials/{id}`). */
    private function employeeHistoryIds(int|string $commercialId, string $query): array
    {
        $rows = $this->getJson("/api/v1/commercials/{$commercialId}?".$query)
            ->assertOk()
            ->json('historique.clients');

        return collect($rows)->pluck('id')->all();
    }

    // ------------------------------------------------------- Pointeur courant

    public function test_reservation_writes_point_the_client_to_its_current_reservation(): void
    {
        $client = $this->makeClient();

        $reservation = $this->reserve($client, Reservation::STATUS_PENDING);

        $client->refresh();
        $this->assertSame($reservation->id, $client->current_reservation_id);
        $this->assertSame($this->commercial->id, $client->current_comercial_id);

        // Issue d'appel : la même réservation reste la courante.
        $reservation->update(['status' => Reservation::STATUS_BV_VOICEMAIL]);
        $this->assertSame($reservation->id, $client->refresh()->current_reservation_id);

        // Retour disponible puis nouvelle réservation : le pointeur bascule
        // sur la plus récente, même règle que `Client::latestReservation()`.
        $client->update(['status' => Client::STATUS_AVAILABLE]);
        $newer = $this->reserve($client, Reservation::STATUS_PENDING);
        $newer->created_at = $reservation->created_at->copy()->addSecond();
        $newer->save();

        $this->assertSame($newer->id, $client->refresh()->current_reservation_id);
        $this->assertSame($this->commercial->id, $client->current_comercial_id);
    }

    public function test_admin_unblock_clears_the_current_reservation_pointers(): void
    {
        $client = $this->makeClient(['status' => Client::STATUS_BLACKLISTED, 'is_blacklisted' => true]);
        $reservation = $this->reserve($client, Reservation::STATUS_YES);
        $this->assertSame($reservation->id, $client->refresh()->current_reservation_id);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/liste-noire/{$client->id}/debloquer")->assertOk();

        // Suppression massive (aucun événement Eloquent) : la synchro est
        // appelée explicitement par le contrôleur.
        $client->refresh();
        $this->assertNull($client->current_reservation_id);
        $this->assertNull($client->current_comercial_id);
    }

    // ------------------------------------------------------- Filtres de statut

    public function test_admin_list_filters_on_the_status_actually_displayed(): void
    {
        $c = $this->fixtureClients();

        Sanctum::actingAs($this->admin);

        // Dimension statut CLIENT (Disponible / Blacklist).
        foreach ([
            Client::STATUS_AVAILABLE => $c['available'],
            Client::STATUS_BLACKLISTED => $c['blacklisted'],
        ] as $value => $client) {
            $this->assertSame(
                [$client->id],
                $this->adminListIds('status='.$value),
                "Filtre `status={$value}`."
            );
        }

        // Dimension statut de RÉSERVATION courante (Oui / Non / BV / À rappeler).
        foreach ([
            Reservation::STATUS_YES => 'oui',
            Reservation::STATUS_NO => 'non',
            Reservation::STATUS_BV_VOICEMAIL => 'bv',
            Reservation::STATUS_CALL_BACK => 'rappel',
        ] as $value => $key) {
            $rows = $this->getJson('/api/v1/commercials/clients?reservation_status='.$value)
                ->assertOk()
                ->json('data.clients');

            $this->assertSame([$c[$key]->id], collect($rows)->pluck('id')->all(), "Filtre `reservation_status={$value}`.");
            // La valeur renvoyée est bien celle que la colonne affiche.
            $this->assertSame($value, $rows[0]['reservation_status']);
        }

        // Un client blacklisté dont la réservation est « YES » reste dans le
        // seau Blacklist : son statut client prime, la réservation l'exclut
        // de sa propre dimension (jeux disjoints).
        $this->assertNotContains(
            $c['blacklisted']->id,
            $this->adminListIds('reservation_status=YES')
        );

        // Union (OR) : sélection mixte des deux dimensions = union exacte.
        $this->assertEqualsCanonicalizing(
            [$c['available']->id, $c['oui']->id],
            $this->adminListIds('status=AVAILABLE&reservation_status=YES')
        );
    }

    public function test_overview_badges_count_exactly_the_rows_the_filters_render(): void
    {
        $this->fixtureClients();

        Sanctum::actingAs($this->admin);

        $badges = $this->getJson('/api/v1/clients/overview')
            ->assertOk()
            ->json('data.by_display_status');

        $this->assertEqualsCanonicalizing(
            [
                Client::STATUS_AVAILABLE,
                Client::STATUS_BLACKLISTED,
                Client::STATUS_SANS_TELEPHONE,
                Reservation::STATUS_YES,
                Reservation::STATUS_NO,
                Reservation::STATUS_BV_VOICEMAIL,
                Reservation::STATUS_CALL_BACK,
                Reservation::STATUS_PENDING,
            ],
            array_keys($badges ?? []),
            'Les 8 badges de la colonne « Statut » sont renvoyés.'
        );

        // Chaque compteur est produit **par le scope qui pilote le filtre** :
        // il doit valoir exactement le nombre de lignes rendues après clic.
        // (`SANS_TELEPHONE` est testé à part : aucun client du jeu n'est
        // sans numéro, son compteur vaut donc 0.)
        $filters = [
            Client::STATUS_AVAILABLE => 'status',
            Client::STATUS_BLACKLISTED => 'status',
            Reservation::STATUS_YES => 'reservation_status',
            Reservation::STATUS_NO => 'reservation_status',
            Reservation::STATUS_BV_VOICEMAIL => 'reservation_status',
            Reservation::STATUS_CALL_BACK => 'reservation_status',
        ];

        foreach ($filters as $bucket => $param) {
            $rows = $this->adminListIds("{$param}={$bucket}");

            $this->assertSame(1, count($rows), "Le jeu de données doit rendre une ligne pour « {$bucket} ».");
            $this->assertSame(1, (int) $badges[$bucket], "Compteur du badge « {$bucket} » = lignes rendues.");
        }

        // Aucune réservation en attente dans le jeu : le badge « - » est à 0.
        $this->assertSame(0, (int) $badges[Reservation::STATUS_PENDING]);
    }

    public function test_admin_grand_list_renders_every_prospect_without_restriction(): void
    {
        // Jamais appelé, aucune réservation : la grande liste admin doit
        // quand même le rendre (plus de condition « déjà contacté »).
        $untouched = $this->makeClient();
        $called = $this->called();

        Sanctum::actingAs($this->admin);

        $ids = $this->adminListIds('');
        $this->assertEqualsCanonicalizing(
            [$untouched->id, $called->id],
            $ids,
            'La grande liste admin rend tous les prospects, réservés ou non, appelés ou non.'
        );

        // Le périmètre est exactement celui du badge « Tous ».
        $system = $this->getJson('/api/v1/clients/overview')
            ->assertOk()
            ->json('data.prospects.system');

        $this->assertSame(2, count($ids));
        $this->assertSame(2, (int) $system, 'Badge « Tous » = lignes de la grande liste.');
    }

    public function test_employee_history_badges_count_exactly_the_rows_they_render(): void
    {
        // Les 6 prospects du jeu ont tous été appelés par $this->commercial.
        $c = $this->fixtureClients();

        Sanctum::actingAs($this->admin);

        $commercialId = $this->commercial->id;
        $badges = $this->getJson("/api/v1/commercials/{$commercialId}")
            ->assertOk()
            ->json('historique.badges');

        $this->assertSame(6, (int) ($badges['prospects']['system'] ?? 0), 'Badge « Tous » = historique de l\'employé.');

        $filters = [
            Client::STATUS_AVAILABLE => 'status',
            Client::STATUS_BLACKLISTED => 'status',
            Reservation::STATUS_YES => 'reservation_status',
            Reservation::STATUS_NO => 'reservation_status',
            Reservation::STATUS_BV_VOICEMAIL => 'reservation_status',
            Reservation::STATUS_CALL_BACK => 'reservation_status',
            Reservation::STATUS_PENDING => 'reservation_status',
        ];

        foreach ($filters as $bucket => $param) {
            $rows = $this->employeeHistoryIds($commercialId, "{$param}={$bucket}");

            $this->assertSame(
                (int) ($badges['by_display_status'][$bucket] ?? 0),
                count($rows),
                "Compteur du badge « {$bucket} » = lignes rendues (employé)."
            );
        }

        // Un prospect du jeu n'appartient PAS à cet employé : il n'entre ni
        // dans son historique ni dans ses compteurs.
        $other = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        $foreign = $this->called();
        Note::where('client_id', $foreign->id)->update(['sender_id' => $other->id]);

        $this->assertNotContains($foreign->id, $this->employeeHistoryIds($commercialId, ''));
        $this->assertSame(6, (int) $this->getJson("/api/v1/commercials/{$commercialId}")
            ->assertOk()
            ->json('historique.badges.prospects.system'));
    }

    // ------------------------------------------------------------- Rappels

    public function test_reminders_payload_exposes_the_current_reservation_status(): void
    {
        $client = $this->makeClient(['status' => Client::STATUS_RESERVED]);
        $reservation = $this->reserve($client, Reservation::STATUS_CALL_BACK);

        Rappel::create([
            'client_id' => $client->id,
            'comercial_id' => $this->commercial->id,
            'reservation_id' => $reservation->id,
            'reminder_date' => now()->addHour(),
        ]);

        Sanctum::actingAs($this->commercial);

        $row = $this->getJson('/api/v1/reminders')->assertOk()->json('data.0');

        $this->assertSame($reservation->id, $client->refresh()->current_reservation_id);
        $this->assertSame(Reservation::STATUS_CALL_BACK, $row['reservation_status']);
        // Le statut client reste aussi fourni : la colonne choisit l'un des deux.
        $this->assertSame(Client::STATUS_RESERVED, $row['client_status']);
    }
}
