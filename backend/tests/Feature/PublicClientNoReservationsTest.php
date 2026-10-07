<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use App\Services\Client\ClientImportService;

/**
 * Endpoint public **temporaire** de fausses réservations « NON » (`POST
 * /api/v1/clients/create-no-reservations`, spec :
 * `docs/create_no_reservations_api.md`).
 *
 * Fil conducteur : aucune authentification, une ligne `reservations`
 * (`status = NO`) par client visé, attribuée à **un seul employé** (jamais
 * répartie sur tous les commerciaux) et **sans aucun effet de bord métier**
 * (ni note `NO`, ni `returned_at`, ni auto-liste-noire — le workflow réel
 * reste `CallWorkflowService::apply()`).
 *
 * Cibles acceptées : `{"status": …}` (tout un périmètre), `licence` /
 * `licences`, `client_id` / `client_ids`, `clients`, liste JSON nue.
 *
 * Rapport consolidé : `processed` (succès) / `created` / `already` /
 * `skipped` / `not_found` / `failed` — **une cible introuvable ou déjà
 * traitée est ignorée, jamais une erreur**, et le lot est ré-exécutable.
 */
class PublicClientNoReservationsTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/clients/create-no-reservations';

    private User $mohamed;

    private User $autre;

    protected function setUp(): void
    {
        parent::setUp();

        // La config pointe par défaut sur l'adresse du seeder de
        // développement ; le test la rend explicite.
        config([
            'public_api.no_reservations_comercial_email' => 'mohamed.khemir@apex-structures.tn',
        ]);

        $this->mohamed = User::factory()->create([
            'email' => 'mohamed.khemir@apex-structures.tn',
            'first_name' => 'Mohamed',
            'last_name' => 'Khemir',
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ]);

        $this->autre = User::factory()->create([
            'email' => 'olfa.hammami@apex-structures.tn',
            'first_name' => 'Olfa',
            'last_name' => 'Hammami',
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ]);
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Prospect',
            'status' => Client::STATUS_UNAVAILABLE,
            'returned_at' => now()->addMonths(3),
        ], $attrs));
    }

    private function rawPost(string $body): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    // ------------------------------------------------- Création (par licence)

    public function test_guest_can_create_a_no_reservation_by_licence_without_authentication(): void
    {
        $client = $this->makeClient([
            'enterprise_name' => 'Ville De Drummondville',
            'licence_number' => '1100-3571-01',
            'licence_propre_numero' => 1100357101,
        ]);

        // Aucune authentification : la requête part sans token.
        $this->postJson(self::URI, ['licence' => '1100357101'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.already', 0)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.not_found', 0)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.comercial_email', 'mohamed.khemir@apex-structures.tn')
            ->assertJsonPath('data.created_items.0.type', ClientImportService::NO_RESERVATION_TARGET_LICENCE)
            ->assertJsonPath('data.created_items.0.key', '1100357101')
            ->assertJsonPath('data.created_items.0.client_id', $client->id)
            ->assertJsonPath('data.created_items.0.enterprise_name', 'Ville De Drummondville');

        $reservation = Reservation::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(Reservation::STATUS_NO, $reservation->status);
        $this->assertSame($this->mohamed->id, $reservation->comercial_id);
        $this->assertNull($reservation->reservation_group_id, 'Aucune liste d\'employé pour une fausse réservation.');

        // Aucun effet de bord métier : le statut du client est inchangé et
        // **aucune note** n'est journalisée (le workflow n'est pas appelé).
        $client->refresh();
        $this->assertSame(Client::STATUS_UNAVAILABLE, $client->status);
        $this->assertNotNull($client->returned_at);
        $this->assertFalse((bool) $client->is_blacklisted);
        $this->assertSame(0, Note::count());
        $this->assertSame(0, Rappel::count());
    }

    public function test_reservations_are_attributed_to_a_single_commercial(): void
    {
        $a = $this->makeClient(['licence_propre_numero' => 1100357101]);
        $b = $this->makeClient(['licence_propre_numero' => 1100545100]);
        $c = $this->makeClient(['licence_propre_numero' => 1104861806]);

        $this->postJson(self::URI, ['licences' => ['1100357101', '1100545100', '1104861806']])
            ->assertOk()
            ->assertJsonPath('data.received', 3)
            ->assertJsonPath('data.matched', 3)
            ->assertJsonPath('data.created', 3);

        // Toutes les réservations sont celles de Mohamed, **aucune** pour les
        // autres commerciaux.
        $this->assertSame(3, Reservation::where('comercial_id', $this->mohamed->id)->count());
        $this->assertSame(0, Reservation::where('comercial_id', $this->autre->id)->count());
        $this->assertSame(0, Reservation::whereNotIn('comercial_id', [$this->mohamed->id, $this->autre->id])->count());

        foreach ([$a, $b, $c] as $client) {
            $this->assertSame(1, $client->reservations()->count());
        }
    }

    public function test_client_ids_and_bare_uuid_list_are_accepted(): void
    {
        $alpha = $this->makeClient(['enterprise_name' => 'Alpha Inc.']);
        $beta = $this->makeClient(['enterprise_name' => 'Beta Inc.']);

        // {"client_ids": [...]}
        $this->postJson(self::URI, ['client_ids' => [$alpha->id, $beta->id]])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.created_items.0.type', ClientImportService::NO_RESERVATION_TARGET_ID);

        // Liste JSON nue : un uuid **et** une licence, sans enveloppe.
        $gamma = $this->makeClient(['enterprise_name' => 'Gamma Inc.', 'licence_propre_numero' => 1105228909]);
        $delta = $this->makeClient(['enterprise_name' => 'Delta Inc.', 'licence_propre_numero' => 1105570416]);
        $this->rawPost(json_encode([$gamma->id, '1105570416']))
            ->assertOk()
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.created', 2);

        $this->assertSame(1, $gamma->reservations()->count());
        $this->assertSame(1, $delta->reservations()->count());
    }

    // --------------------------------------------------- Mode « statut »

    public function test_status_mode_creates_one_reservation_per_unavailable_client(): void
    {
        $unavailableA = $this->makeClient(['enterprise_name' => 'Indisponible A']);
        $unavailableB = $this->makeClient(['enterprise_name' => 'Indisponible B']);
        $available = $this->makeClient([
            'enterprise_name' => 'Disponible',
            'status' => Client::STATUS_AVAILABLE,
            'returned_at' => null,
        ]);

        $this->postJson(self::URI, ['status' => 'UNAVAILABLE'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 2)
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.truncated', false);

        $this->assertSame(1, $unavailableA->reservations()->count());
        $this->assertSame(1, $unavailableB->reservations()->count());
        $this->assertSame(0, $available->reservations()->count(), 'Le périmètre ne déborde pas sur AVAILABLE.');

        // Le statut des clients visés est inchangé (aucun effet de bord).
        $this->assertSame(Client::STATUS_UNAVAILABLE, $unavailableA->refresh()->status);
        $this->assertSame(Client::STATUS_AVAILABLE, $available->refresh()->status);
    }

    public function test_status_mode_ignores_case_and_unknown_status_is_refused(): void
    {
        $client = $this->makeClient(['status' => Client::STATUS_UNAVAILABLE]);

        $this->postJson(self::URI, ['status' => ' unavailable '])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->postJson(self::URI, ['status' => 'PEUT-ETRE'])->assertStatus(422);
        $this->postJson(self::URI, ['status' => 42])->assertStatus(422);
        $this->postJson(self::URI, ['status' => 'UNAVAILABLE', 'licences' => ['1100357101']])
            ->assertStatus(422);

        $this->assertSame(1, $client->reservations()->count());
    }

    public function test_status_mode_plafonne_le_perimetre(): void
    {
        // Le plafond vit dans le modèle ; on vérifie le contrat du rapport
        // (une seule page, `next_after` nul quand tout est passé) plutôt que
        // sa valeur — 20 000 clients à créer en base serait absurde.
        $client = $this->makeClient();

        $this->postJson(self::URI, ['status' => Client::STATUS_UNAVAILABLE])
            ->assertOk()
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.next_after', null)
            ->assertJsonPath('data.received', 1);

        $this->assertSame(1, $client->reservations()->count());
    }

    public function test_status_mode_cursor_skips_already_processed_clients(): void
    {
        // Plafond forcé à 2 : le premier appel doit s'arrêter et rendre un
        // `next_after`, le second passer le reste (et **rien d'autre**).
        config(['public_api.no_reservations_status_limit' => 2]);

        $this->makeClient(['enterprise_name' => 'Curseur A']);
        $this->makeClient(['enterprise_name' => 'Curseur B']);
        $this->makeClient(['enterprise_name' => 'Curseur C']);

        $first = $this->postJson(self::URI, ['status' => Client::STATUS_UNAVAILABLE])
            ->assertOk()
            ->assertJsonPath('data.received', 2)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.truncated', true);

        $after = $first->json('data.next_after');
        $this->assertNotNull($after, 'Un périmètre tronqué doit rendre un curseur.');

        $this->postJson(self::URI, ['status' => Client::STATUS_UNAVAILABLE, 'after' => $after])
            ->assertOk()
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.already', 0)
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.next_after', null);

        $this->assertSame(3, Reservation::count(), 'Chaque client reçoit une seule réservation.');

        // Un `after` déjà passé ne ré-traite rien (idempotence du curseur).
        $this->postJson(self::URI, ['status' => Client::STATUS_UNAVAILABLE, 'after' => $after])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.already', 1);

        $this->assertSame(3, Reservation::count());
    }

    public function test_status_mode_rejects_a_non_string_cursor(): void
    {
        $this->makeClient();

        $this->postJson(self::URI, ['status' => Client::STATUS_UNAVAILABLE, 'after' => 42])
            ->assertStatus(422);

        $this->assertSame(0, Reservation::count());
    }

    // ------------------------------------------------------------- Idempotence

    public function test_rerun_is_idempotent_and_counts_already_no(): void
    {
        $client = $this->makeClient(['licence_propre_numero' => 1107833420]);

        $this->postJson(self::URI, ['licence' => '1107833420'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.already', 0);

        // 2e passage : rien à créer, une cible « already_no », **zéro erreur**.
        $this->postJson(self::URI, ['licence' => '1107833420'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.already', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.ignored_items.0.reason', 'already_no');

        $this->assertSame(1, $client->reservations()->count(), 'Pas de doublon au second passage.');
    }

    public function test_a_no_reservation_from_another_commercial_does_not_block(): void
    {
        $client = $this->makeClient(['licence_propre_numero' => 1104861806]);

        // Un « NON » d'un autre employé n'empêche pas celui de Mohamed : la
        // règle porte sur le couple (client, employé).
        Reservation::create([
            'client_id' => $client->id,
            'comercial_id' => $this->autre->id,
            'status' => Reservation::STATUS_NO,
        ]);

        $this->postJson(self::URI, ['licence' => '1104861806'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.already', 0);

        $this->assertSame(2, $client->reservations()->count());
    }

    public function test_blacklisted_clients_are_skipped(): void
    {
        $noir = $this->makeClient([
            'licence_propre_numero' => 1105228909,
            'status' => Client::STATUS_BLACKLISTED,
            'is_blacklisted' => true,
        ]);

        $this->postJson(self::URI, ['licence' => '1105228909'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.ignored_items.0.reason', 'blacklisted');

        $this->assertSame(0, $noir->reservations()->count());
    }

    public function test_unknown_target_is_ignored_and_invalid_item_is_failed(): void
    {
        $present = $this->makeClient(['licence_propre_numero' => 1100357101]);

        $response = $this->postJson(self::URI, ['licences' => [
            '1100357101',            // → créé
            '9999999999',            // → ignoré (not_found)
            ['municipality' => 'x'], // → échec (aucune clé)
        ]]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 3)
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.errors.0.index', 2);

        $this->assertSame(1, $present->reservations()->count());
    }

    public function test_missing_employee_is_reported_instead_of_a_silent_failure(): void
    {
        config(['public_api.no_reservations_comercial_email' => 'absent@exemple.test']);
        $client = $this->makeClient(['licence_propre_numero' => 1100357101]);

        $this->postJson(self::URI, ['licence' => '1100357101'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, $client->reservations()->count());
    }

    // ------------------------------------------------------------- Validation

    public function test_invalid_bodies_are_rejected_with_422(): void
    {
        $keep = $this->makeClient(['licence_propre_numero' => 1100357101]);

        $this->postJson(self::URI, ['foo' => 'bar'])->assertStatus(422);
        $this->postJson(self::URI, ['licences' => []])->assertStatus(422);
        $this->postJson(self::URI, ['client_id' => '   '])->assertStatus(422);
        $this->postJson(self::URI, ['licence' => ['a' => 'b']])->assertStatus(422);
        $this->rawPost('"pas un tableau"')->assertStatus(422);

        $this->assertSame(0, $keep->reservations()->count());
    }

    public function test_lot_size_is_bounded(): void
    {
        config(['public_api.max_items' => 1]);

        $a = $this->makeClient(['licence_propre_numero' => 1100357101]);
        $b = $this->makeClient(['licence_propre_numero' => 1100545100]);

        $this->postJson(self::URI, ['licences' => ['1100357101', '1100545100']])->assertStatus(422);

        $this->assertSame(0, $a->reservations()->count());
        $this->assertSame(0, $b->reservations()->count());
    }

    // ------------------------------------------------------------------ CORS

    public function test_cors_preflight_is_answered_on_the_public_route(): void
    {
        $response = $this->call('OPTIONS', self::URI, [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertSame(
            'http://localhost:5173',
            $response->headers->get('Access-Control-Allow-Origin'),
            'Le preflight CORS doit répondre pour le endpoint public.'
        );
    }
}
