<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CallWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Endpoint public **temporaire** d'indisponibilité en masse par numéro de
 * téléphone (`POST /api/v1/clients/convert-to-unavailable`,
 * spec : docs/convert_to_unavailable_api.md).
 *
 * Fil conducteur : aucune authentification, lot de numéros **détectés
 * quel que soit leur format** (`819-418-6550`, `8194186550`,
 * `+1819-418-6550`, `+18194186550`, `+1-819-418-6550`… — des deux côtés,
 * cf. `Client::normalizePhone()`), toutes les lignes visées passent en
 * `UNAVAILABLE` avec `returned_at = now + 3 mois` (geste NO, RULES §3 :
 * rappels annulés, réservations conservées, note `NOTE` émise par
 * `SYSTEM`).
 *
 * Rapport consolidé : `processed` (succès) / `blocked` / `ignored` /
 * `failed` (erreurs) — **un numéro introuvable est ignoré, jamais une
 * erreur**, le lot est ré-exécutable (idempotent), et une ligne en liste
 * noire n'est **jamais rétrogradée**.
 */
class PublicClientConvertToUnavailableTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/clients/convert-to-unavailable';

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // Webhooks publics protégés : en-tête X-Api-Key (VerifyExternalSystemKey).
        $this->withHeaders(['X-Api-Key' => (string) config('services.external_system.key')]);

        $this->commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Prospect',
            'status' => Client::STATUS_AVAILABLE,
        ], $attrs));
    }

    private function rawPost(string $body): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            // `$this->call()` n'applique PAS les defaultHeaders de withHeaders().
            'HTTP_X_API_KEY' => (string) config('services.external_system.key'),
        ], $body);
    }

    // ----------------------------------------------------------- Blocage

    public function test_guest_can_block_by_phone_without_authentication(): void
    {
        $client = $this->makeClient(['phone' => '819-418-6550']);

        // Aucune authentification : la requête part sans token, et la
        // saisie est dans une autre forme que la colonne stockée.
        $this->postJson(self::URI, ['phone' => '+1-819-418-6550'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.blocked', 1)
            ->assertJsonPath('data.ignored', 0)
            ->assertJsonPath('data.not_found', 0)
            ->assertJsonPath('data.already_unavailable', 0)
            ->assertJsonPath('data.blacklisted', 0)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.blocked_items.0.index', 0)
            ->assertJsonPath('data.blocked_items.0.phone', '+1-819-418-6550')
            ->assertJsonPath('data.blocked_items.0.matched', 1)
            ->assertJsonPath('data.blocked_items.0.blocked', 1);

        // Geste NO : UNAVAILABLE + retour dans 3 mois.
        $client->refresh();
        $this->assertSame(Client::STATUS_UNAVAILABLE, $client->status);
        $this->assertEqualsWithDelta(
            now()->addMonths(CallWorkflowService::NON_BLOCK_MONTHS)->timestamp,
            $client->returned_at->timestamp,
            5
        );

        // Journal `NOTE`, émetteur `SYSTEM` (pas d'utilisateur côté API).
        $note = Note::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(Note::TYPE_NOTE, $note->type);
        $this->assertSame(Note::SENDER_SYSTEM, $note->sender_id);
    }

    public function test_every_supported_format_is_detected_on_both_sides(): void
    {
        // Colonne `phone` stockée sous 4 formes différentes d'un même numéro.
        foreach (['819-418-6550', '8194186550', '1 819-418-6550', '819-418-6550 ext. 5417'] as $stored) {
            $this->makeClient(['phone' => $stored]);
        }

        $inputs = [
            '819-418-6550',
            '8194186550',
            '+1819-418-6550',
            '+18194186550',
            '+1-819-418-6550',
            '(819) 418 6550',
        ];

        foreach ($inputs as $index => $input) {
            $data = $this->postJson(self::URI, ['phone' => $input])
                ->assertOk()
                ->json('data');

            // Les 4 lignes sont détectées à chaque saisie…
            $this->assertSame(4, $data['matched'], $input);
            // …la première bloque les 4, les suivantes ne réécrivent rien.
            $this->assertSame($index === 0 ? 4 : 0, $data['blocked'], $input);
            $this->assertSame($index === 0 ? 0 : 4, $data['already_unavailable'], $input);
        }

        // Ligne d'un autre numéro : jamais touchée.
        $other = $this->makeClient(['phone' => '418-555-1212']);
        $this->postJson(self::URI, ['phone' => '418-555-1212'])->assertOk();
        $this->assertSame(Client::STATUS_UNAVAILABLE, $other->refresh()->status);
    }

    public function test_every_row_sharing_the_number_is_blocked(): void
    {
        $first = $this->makeClient(['phone' => '+18194186550']);
        $twin = $this->makeClient(['phone' => '819-418-6550']);
        $other = $this->makeClient(['phone' => '418-555-1212']);

        $this->postJson(self::URI, ['phone' => '+1-819-418-6550'])
            ->assertOk()
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.blocked', 2)
            ->assertJsonPath('data.not_found', 0);

        $this->assertSame(Client::STATUS_UNAVAILABLE, $first->refresh()->status);
        $this->assertSame(Client::STATUS_UNAVAILABLE, $twin->refresh()->status);
        $this->assertSame(Client::STATUS_AVAILABLE, $other->refresh()->status);
    }

    public function test_rappels_are_cancelled_while_reservations_are_kept(): void
    {
        $client = $this->makeClient(['phone' => '819-418-6550']);

        $reservation = Reservation::create([
            'client_id' => $client->id,
            'comercial_id' => $this->commercial->id,
            'status' => Reservation::STATUS_PENDING,
        ]);

        Rappel::create([
            'client_id' => $client->id,
            'comercial_id' => $this->commercial->id,
            'reservation_id' => $reservation->id,
            'reminder_date' => now()->addDays(3),
        ]);

        $this->postJson(self::URI, ['phone' => '819-418-6550'])
            ->assertOk()
            ->assertJsonPath('data.blocked', 1);

        // Sinon le cron des rappels expirés ré-appliquerait son blocage
        // 21 j et écraserait le retour à 3 mois.
        $this->assertSame(0, Rappel::where('client_id', $client->id)->count(), 'Rappels annulés.');
        // L'indisponibilité ne supprime jamais l'historique.
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    // --------------------------------------------------- Rapport consolidé

    public function test_report_counts_success_blocked_ignored_and_errors(): void
    {
        $this->makeClient(['phone' => '819-418-6550']);   // → bloqué
        $this->makeClient([                               // → ignoré (blacklisté)
            'phone' => '418-555-1212',
            'status' => Client::STATUS_BLACKLISTED,
            'is_blacklisted' => true,
        ]);

        $this->postJson(self::URI, ['phones' => [
            '819-418-6550',          // → bloqué
            '418-555-1212',          // → ignoré (blacklisted)
            '514-555-0000',          // → ignoré (not_found), **pas** une erreur
            ['municipality' => 'x'], // → échec (aucune clé de téléphone)
            'pas-un-numéro',         // → échec (aucun chiffre)
        ]])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 5)
            ->assertJsonPath('data.processed', 3)   // succès
            ->assertJsonPath('data.blocked', 1)
            ->assertJsonPath('data.ignored', 2)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.already_unavailable', 0)
            ->assertJsonPath('data.blacklisted', 1)
            ->assertJsonPath('data.failed', 2)
            ->assertJsonPath('data.ignored_items.0.reason', 'blacklisted')
            ->assertJsonPath('data.ignored_items.1.reason', 'not_found')
            ->assertJsonPath('data.errors.0.index', 3)
            ->assertJsonPath('data.errors.1.index', 4);
    }

    public function test_unknown_numbers_are_ignored_not_failed(): void
    {
        $present = $this->makeClient(['phone' => '819-418-6550']);

        $this->postJson(self::URI, ['phone' => '514-555-0000'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.matched', 0)
            ->assertJsonPath('data.blocked', 0)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.ignored_items.0.index', 0)
            ->assertJsonPath('data.ignored_items.0.phone', '514-555-0000')
            ->assertJsonPath('data.ignored_items.0.reason', 'not_found');

        $this->assertSame(Client::STATUS_AVAILABLE, $present->refresh()->status, 'Ligne non visée : intacte.');
    }

    public function test_already_unavailable_rows_are_counted_and_not_rewritten(): void
    {
        $client = $this->makeClient([
            'phone' => '819-418-6550',
            'status' => Client::STATUS_UNAVAILABLE,
            'returned_at' => now()->addDays(10),
        ]);
        $client->forceFill(['updated_at' => now()->subDay()])->save();
        $frozen = $client->refresh()->updated_at;

        $this->postJson(self::URI, ['phone' => '819-418-6550'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.blocked', 0)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.already_unavailable', 1)
            ->assertJsonPath('data.ignored_items.0.reason', 'already_unavailable');

        $this->assertTrue(
            $frozen->equalTo($client->refresh()->updated_at),
            'Une ligne déjà indisponible n\'est pas réécrite.'
        );
        $this->assertSame(0, Note::count(), 'Aucune note dupliquée.');
    }

    public function test_blacklisted_clients_are_never_demoted(): void
    {
        $client = $this->makeClient([
            'phone' => '819-418-6550',
            'status' => Client::STATUS_BLACKLISTED,
            'is_blacklisted' => true,
            'returned_at' => null,
        ]);

        $this->postJson(self::URI, ['phone' => '819-418-6550'])
            ->assertOk()
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.blocked', 0)
            ->assertJsonPath('data.blacklisted', 1);

        $client->refresh();
        $this->assertSame(Client::STATUS_BLACKLISTED, $client->status);
        $this->assertTrue((bool) $client->is_blacklisted);
        $this->assertNull($client->returned_at, 'La liste noire ne gagne jamais de compte à rebours.');
    }

    public function test_rerun_is_idempotent_and_ignores_unknown_numbers(): void
    {
        $client = $this->makeClient(['phone' => '819-418-6550']);

        // 1er passage.
        $this->postJson(self::URI, ['phones' => ['819-418-6550', '514-555-0000']])
            ->assertOk()
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.blocked', 1)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertSame(Client::STATUS_UNAVAILABLE, $client->refresh()->status);

        // 2e passage : rien à bloquer, tout est ignoré, **zéro erreur**.
        $this->postJson(self::URI, ['phones' => ['819-418-6550', '514-555-0000']])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.blocked', 0)
            ->assertJsonPath('data.ignored', 2)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.already_unavailable', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertSame(1, Note::count(), 'Un seul journal malgré deux passages.');
    }

    // ------------------------------------------------------------- Formes

    public function test_single_envelope_bare_list_and_objects_are_all_accepted(): void
    {
        $alpha = $this->makeClient(['phone' => '819-418-6550']);
        $beta = $this->makeClient(['phone' => '418-555-1212']);
        $gamma = $this->makeClient(['phone' => '506-555-1212']);
        $delta = $this->makeClient(['phone' => '902-555-1212']);

        // {"phone": "..."} — un seul numéro.
        $this->postJson(self::URI, ['phone' => '819-418-6550'])
            ->assertOk()->assertJsonPath('data.blocked', 1);

        // {"phones": [...]}
        $this->postJson(self::URI, ['phones' => ['418-555-1212']])
            ->assertOk()->assertJsonPath('data.blocked', 1);

        // {"clients": [{"phone": "..."}]}
        $this->rawPost(json_encode(['clients' => [['phone' => '506-555-1212']]]))
            ->assertOk()->assertJsonPath('data.blocked', 1);

        // Liste JSON nue d'objets (clé française).
        $this->rawPost(json_encode([['Téléphone' => '902-555-1212']], JSON_UNESCAPED_UNICODE))
            ->assertOk()->assertJsonPath('data.blocked', 1);

        foreach ([$alpha, $beta, $gamma, $delta] as $client) {
            $this->assertSame(Client::STATUS_UNAVAILABLE, $client->refresh()->status);
        }
    }

    // ------------------------------------------------------ Erreurs partielles

    public function test_partial_failures_keep_the_rest_of_the_batch(): void
    {
        $ok = $this->makeClient(['phone' => '819-418-6550']);

        $response = $this->postJson(self::URI, ['phones' => [
            ['municipality' => 'sans numéro'],   // → échec (aucune clé de téléphone)
            '819-418-6550',                      // → traité
        ]]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.blocked', 1)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.errors.0.index', 0);

        $this->assertNull($response->json('data.errors.0.phone'));
        $this->assertNotEmpty($response->json('data.errors.0.error'));
        $this->assertSame(Client::STATUS_UNAVAILABLE, $ok->refresh()->status, 'La ligne valide est malgré tout traitée.');
    }

    public function test_batch_where_every_item_fails_reports_success_false(): void
    {
        $this->postJson(self::URI, ['phones' => ['abc', ';;;']])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.failed', 2);

        $this->assertSame(0, Note::count());
    }

    // ------------------------------------------------------------ Validation

    public function test_invalid_bodies_are_rejected_with_422(): void
    {
        $keep = $this->makeClient(['phone' => '819-418-6550']);

        $this->postJson(self::URI, ['foo' => 'bar'])->assertStatus(422);
        $this->postJson(self::URI, ['phones' => []])->assertStatus(422);
        $this->postJson(self::URI, ['phone' => '   '])->assertStatus(422);
        $this->postJson(self::URI, ['phone' => 8194186550])->assertStatus(422);
        $this->rawPost('"pas un tableau"')->assertStatus(422);

        // Un corps invalide ne bloque rien.
        $this->assertSame(Client::STATUS_AVAILABLE, $keep->refresh()->status);
    }

    public function test_lot_size_is_bounded(): void
    {
        config(['public_api.max_items' => 1]);

        $a = $this->makeClient(['phone' => '819-418-6550']);
        $b = $this->makeClient(['phone' => '418-555-1212']);

        $this->postJson(self::URI, ['phones' => ['819-418-6550', '418-555-1212']])
            ->assertStatus(422);

        $this->assertSame(Client::STATUS_AVAILABLE, $a->refresh()->status, 'Un lot trop long est refusé intégralement.');
        $this->assertSame(Client::STATUS_AVAILABLE, $b->refresh()->status);
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
