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

/**
 * Endpoint public **temporaire** de conversion en liste noire par nom
 * (`POST /api/v1/clients/convert-to-blacklist`,
 * spec : docs/convert_to_blacklist_api.md).
 *
 * Fil conducteur : aucune authentification, lot de noms, recherche
 * **insensitive à la casse** sur `enterprise_name` **ou** `name`, toutes les
 * lignes correspondant au nom passent en liste noire (geste métier §3.4 :
 * `is_blacklisted`, `status = BLACKLISTED`, `returned_at` vidé, rappels
 * annulés, réservations conservées, note `BLACKLISTED` émise par `SYSTEM`).
 *
 * Rapport consolidé : `processed` (succès) / `zapped` / `ignored` / `failed`
 * (erreurs) — **un nom introuvable est ignoré, jamais une erreur**, et le
 * lot est ré-exécutable (idempotent).
 */
class PublicClientConvertToBlacklistTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/clients/convert-to-blacklist';

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

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
        ], $body);
    }

    // --------------------------------------------------------- Conversion

    public function test_guest_can_blacklist_by_enterprise_name_without_authentication(): void
    {
        $client = $this->makeClient([
            'name' => 'Richard Forget',
            'enterprise_name' => 'Entreprises Richard Forget & Fils Inc.',
            'returned_at' => now()->addDays(5),
        ]);

        // Aucune authentification : la requête part sans token.
        $this->postJson(self::URI, ['name' => 'Entreprises Richard Forget & Fils Inc.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.zapped', 1)
            ->assertJsonPath('data.ignored', 0)
            ->assertJsonPath('data.not_found', 0)
            ->assertJsonPath('data.already_blacklisted', 0)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.zapped_items.0.index', 0)
            ->assertJsonPath('data.zapped_items.0.name', 'Entreprises Richard Forget & Fils Inc.')
            ->assertJsonPath('data.zapped_items.0.matched', 1)
            ->assertJsonPath('data.zapped_items.0.zapped', 1);

        // Geste métier §3.4.
        $client->refresh();
        $this->assertTrue((bool) $client->is_blacklisted);
        $this->assertSame(Client::STATUS_BLACKLISTED, $client->status);
        $this->assertNull($client->returned_at);

        // Journal `BLACKLISTED`, émetteur `SYSTEM` (pas d'utilisateur côté API).
        $note = Note::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(Note::TYPE_BLACKLISTED, $note->type);
        $this->assertSame(Note::SENDER_SYSTEM, $note->sender_id);
    }

    public function test_matching_is_case_insensitive_on_both_sides(): void
    {
        $upper = $this->makeClient(['name' => 'ACME Construction', 'enterprise_name' => 'ACME Construction']);
        $lower = $this->makeClient(['name' => 'beta inc.', 'enterprise_name' => 'beta inc.']);

        // Base en MAJUSCULES → requête en minuscules, et l'inverse.
        $this->postJson(self::URI, ['names' => ['acme CONSTRUCTION', 'BETA INC.']])
            ->assertOk()
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.zapped', 2);

        $this->assertTrue((bool) $upper->refresh()->is_blacklisted);
        $this->assertTrue((bool) $lower->refresh()->is_blacklisted);
    }

    public function test_name_column_is_matched_when_enterprise_name_differs(): void
    {
        $named = $this->makeClient(['name' => 'Jean Dupont', 'enterprise_name' => 'Autre raison sociale']);
        $noCompany = $this->makeClient(['name' => 'Sans Entreprise', 'enterprise_name' => null]);

        $this->postJson(self::URI, ['name' => 'Jean Dupont'])
            ->assertOk()
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.zapped', 1);

        $this->assertTrue((bool) $named->refresh()->is_blacklisted);
        $this->assertFalse((bool) $noCompany->refresh()->is_blacklisted, 'Ligne non visée : intacte.');
    }

    public function test_every_row_sharing_the_name_is_blacklisted(): void
    {
        $first = $this->makeClient(['enterprise_name' => 'Doublon Inc.']);
        $twin = $this->makeClient(['enterprise_name' => 'doublon inc.']);
        $other = $this->makeClient(['enterprise_name' => 'Autre Inc.']);

        $this->postJson(self::URI, ['name' => 'Doublon Inc.'])
            ->assertOk()
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.zapped', 2)
            ->assertJsonPath('data.not_found', 0);

        $this->assertTrue((bool) $first->refresh()->is_blacklisted);
        $this->assertTrue((bool) $twin->refresh()->is_blacklisted);
        $this->assertFalse((bool) $other->refresh()->is_blacklisted);
    }

    public function test_a_partial_name_reaches_every_row_that_contains_it(): void
    {
        // Le mode **nom** est une recherche **partielle** (`LIKE '%nom%'`,
        // insensible à la casse) : c'est le comportement historique de
        // l'endpoint, contrairement au mode licence (égalité exacte).
        $first = $this->makeClient(['enterprise_name' => 'Entreprises Richard Forget & Fils Inc.']);
        $second = $this->makeClient(['enterprise_name' => 'FORGET Construction']);
        $other = $this->makeClient(['enterprise_name' => 'Sans rapport Inc.']);

        $this->postJson(self::URI, ['name' => 'forget'])
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.zapped', 2)
            ->assertJsonPath('data.failed', 0);

        $this->assertTrue((bool) $first->refresh()->is_blacklisted);
        $this->assertTrue((bool) $second->refresh()->is_blacklisted);
        $this->assertFalse((bool) $other->refresh()->is_blacklisted);
    }

    public function test_already_blacklisted_rows_are_counted_and_not_rewritten(): void
    {
        $client = $this->makeClient([
            'enterprise_name' => 'Noir Inc.',
            'status' => Client::STATUS_BLACKLISTED,
            'is_blacklisted' => true,
        ]);
        $client->forceFill(['updated_at' => now()->subDay()])->save();
        $frozen = $client->refresh()->updated_at;

        $this->postJson(self::URI, ['name' => 'Noir Inc.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.zapped', 0)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.already_blacklisted', 1)
            ->assertJsonPath('data.ignored_items.0.index', 0)
            ->assertJsonPath('data.ignored_items.0.name', 'Noir Inc.')
            ->assertJsonPath('data.ignored_items.0.reason', 'already_blacklisted');

        $this->assertTrue(
            $frozen->equalTo($client->refresh()->updated_at),
            'Une ligne déjà en liste noire n\'est pas réécrite.'
        );
        $this->assertSame(0, Note::count(), 'Aucune note dupliquée.');
    }

    public function test_rappels_are_cancelled_while_reservations_are_kept(): void
    {
        $client = $this->makeClient(['enterprise_name' => 'Rappel Inc.']);

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

        $this->postJson(self::URI, ['name' => 'Rappel Inc.'])
            ->assertOk()
            ->assertJsonPath('data.zapped', 1);

        $this->assertSame(0, Rappel::where('client_id', $client->id)->count(), 'Rappels annulés.');
        // §3.4 : la liste noire ne supprime pas l'historique.
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    // ------------------------------------------------------ Rapport consolidé

    public function test_report_counts_success_zapped_ignored_and_errors(): void
    {
        $this->makeClient(['enterprise_name' => 'Présent Inc.']);
        $this->makeClient([
            'enterprise_name' => 'Déjà Inc.',
            'status' => Client::STATUS_BLACKLISTED,
            'is_blacklisted' => true,
        ]);

        $this->postJson(self::URI, ['names' => [
            'Présent Inc.',            // → zappé
            'Inexistant Inc.',         // → ignoré (not_found), **pas** une erreur
            'Déjà Inc.',               // → ignoré (already_blacklisted)
            ['municipality' => 'x'],   // → échec (aucune clé de nom)
        ]])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 4)
            ->assertJsonPath('data.processed', 3)   // succès
            ->assertJsonPath('data.zapped', 1)
            ->assertJsonPath('data.ignored', 2)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.already_blacklisted', 1)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.ignored_items.0.reason', 'not_found')
            ->assertJsonPath('data.ignored_items.1.reason', 'already_blacklisted')
            ->assertJsonPath('data.errors.0.index', 3);
    }

    public function test_rerun_is_idempotent_and_ignores_unknown_names(): void
    {
        $client = $this->makeClient(['enterprise_name' => 'Retour Inc.']);

        // 1er passage.
        $this->postJson(self::URI, ['names' => ['Retour Inc.', 'Absent Inc.']])
            ->assertOk()
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.zapped', 1)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertTrue((bool) $client->refresh()->is_blacklisted);

        // 2e passage : rien à zapper, tout est ignoré, **zéro erreur**.
        $this->postJson(self::URI, ['names' => ['Retour Inc.', 'Absent Inc.', 'Retour Inc.']])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processed', 3)
            ->assertJsonPath('data.zapped', 0)
            ->assertJsonPath('data.ignored', 3)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.already_blacklisted', 2)
            ->assertJsonPath('data.failed', 0);

        $this->assertSame(1, Note::count(), 'Un seul journal malgré deux passages.');
    }

    // ------------------------------------------------------ Absents / formes

    public function test_unknown_names_are_ignored_not_failed(): void
    {
        $present = $this->makeClient(['enterprise_name' => 'Présent Inc.']);

        $this->postJson(self::URI, ['names' => ['Présent Inc.', 'Inexistant Inc.']])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.zapped', 1)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.ignored_items.0.index', 1)
            ->assertJsonPath('data.ignored_items.0.name', 'Inexistant Inc.')
            ->assertJsonPath('data.ignored_items.0.reason', 'not_found');

        $this->assertTrue((bool) $present->refresh()->is_blacklisted);
    }

    public function test_names_envelope_objects_and_bare_list_are_all_accepted(): void
    {
        $alpha = $this->makeClient(['enterprise_name' => 'Alpha Inc.']);
        $beta = $this->makeClient(['enterprise_name' => 'Beta Inc.']);
        $gamma = $this->makeClient(['enterprise_name' => 'Gamma Inc.']);

        // {"names": [...]}
        $this->postJson(self::URI, ['names' => ['Alpha Inc.']])
            ->assertOk()
            ->assertJsonPath('data.zapped', 1);

        // {"clients": [{"enterprise_name": "…"}]}
        $this->rawPost(json_encode(
            ['clients' => [['enterprise_name' => 'Beta Inc.']]],
            JSON_UNESCAPED_UNICODE
        ))->assertOk()->assertJsonPath('data.zapped', 1);

        // Liste JSON nue de chaînes.
        $this->rawPost(json_encode(['Gamma Inc.'], JSON_UNESCAPED_UNICODE))
            ->assertOk()
            ->assertJsonPath('data.zapped', 1);

        $this->assertTrue((bool) $alpha->refresh()->is_blacklisted);
        $this->assertTrue((bool) $beta->refresh()->is_blacklisted);
        $this->assertTrue((bool) $gamma->refresh()->is_blacklisted);
    }

    // ------------------------------------------------------ Cible : licence

    public function test_guest_can_blacklist_by_licence_propre_without_authentication(): void
    {
        $client = $this->makeClient([
            'enterprise_name' => 'Ville De Drummondville',
            'licence_number' => '1100-3571-01',
            'licence_propre_numero' => 1100357101,
        ]);

        $this->postJson(self::URI, ['licence' => '1100357101'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.zapped', 1)
            ->assertJsonPath('data.failed', 0)
            // Ligne de rapport : cible = licence, `name` neutre.
            ->assertJsonPath('data.zapped_items.0.index', 0)
            ->assertJsonPath('data.zapped_items.0.type', Client::BLACKLIST_MODE_LICENCE)
            ->assertJsonPath('data.zapped_items.0.key', '1100357101')
            ->assertJsonPath('data.zapped_items.0.licence', '1100357101')
            ->assertJsonPath('data.zapped_items.0.name', null)
            ->assertJsonPath('data.zapped_items.0.zapped', 1);

        $client->refresh();
        $this->assertTrue((bool) $client->is_blacklisted);
        $this->assertSame(Client::STATUS_BLACKLISTED, $client->status);

        // La note reprend la licence visée.
        $note = Note::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(
            'Liste noire (API publique) : licence 1100357101',
            $note->description
        );
    }

    public function test_licence_number_column_is_used_when_propre_is_absent(): void
    {
        $client = $this->makeClient([
            'enterprise_name' => 'Emard Couvre-Planchers inc.',
            'licence_number' => '1104-8618-06',
            'licence_propre_numero' => null,
        ]);

        // « Licence » (texte, forme `XXXX-XXXX-XX`) : pas de repli numérique.
        $this->postJson(self::URI, ['licence' => '1104-8618-06'])
            ->assertOk()
            ->assertJsonPath('data.matched', 1)
            ->assertJsonPath('data.zapped', 1);

        $this->assertTrue((bool) $client->refresh()->is_blacklisted);
    }

    public function test_numeric_licence_reaches_both_licence_columns(): void
    {
        $propre = $this->makeClient([
            'enterprise_name' => 'Par le numéro RBQ',
            'licence_propre_numero' => 1105228909,
        ]);
        $text = $this->makeClient([
            'enterprise_name' => 'Par le texte Licence',
            'licence_number' => '1105228909',
        ]);
        $other = $this->makeClient(['enterprise_name' => 'Autre']);

        // Valeur numérique pure → `licence_propre_numero` **et**
        // `licence_number` sont visés (le champ « Licence (propre) » du
        // registre peut être stocké dans l'un ou l'autre selon l'import).
        $this->postJson(self::URI, ['licence' => '1105228909'])
            ->assertOk()
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.zapped', 2);

        $this->assertTrue((bool) $propre->refresh()->is_blacklisted);
        $this->assertTrue((bool) $text->refresh()->is_blacklisted);
        $this->assertFalse((bool) $other->refresh()->is_blacklisted);
    }

    public function test_text_licence_never_matches_a_similar_numeric_one(): void
    {
        $client = $this->makeClient([
            'enterprise_name' => 'Roy & Fils Ltée',
            'licence_propre_numero' => 1105228909,
        ]);

        // `RB-1105228909` ne doit pas valider le numéro RBQ 1105228909.
        $this->postJson(self::URI, ['licence' => 'RB-1105228909'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.matched', 0)
            ->assertJsonPath('data.zapped', 0)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertFalse((bool) $client->refresh()->is_blacklisted);
    }

    public function test_licences_envelope_and_scraper_items_are_accepted(): void
    {
        $alpha = $this->makeClient(['enterprise_name' => 'Alpha Inc.', 'licence_propre_numero' => 1100357101]);
        $beta = $this->makeClient(['enterprise_name' => 'Beta Inc.', 'licence_number' => '1104-8618-06']);
        $gamma = $this->makeClient(['enterprise_name' => 'Gamma Inc.', 'licence_number' => '1100-3571-01']);

        // {"licences": [...]}
        $this->postJson(self::URI, ['licences' => ['1100357101', '1104-8618-06']])
            ->assertOk()
            ->assertJsonPath('data.received', 2)
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.zapped', 2);

        // Liste JSON nue d'objets scraper : la clé de licence prime sur le
        // nom (qui ne correspondrait à aucune ligne).
        $this->rawPost(json_encode([
            ['Licence' => '1100-3571-01', 'Nom de l\'intervenant / Entreprise' => 'Inexistante Inc.'],
        ], JSON_UNESCAPED_UNICODE))
            ->assertOk()
            ->assertJsonPath('data.zapped_items.0.type', Client::BLACKLIST_MODE_LICENCE)
            ->assertJsonPath('data.zapped_items.0.licence', '1100-3571-01')
            ->assertJsonPath('data.matched', 1);

        $this->assertTrue((bool) $alpha->refresh()->is_blacklisted);
        $this->assertTrue((bool) $beta->refresh()->is_blacklisted);
        $this->assertTrue((bool) $gamma->refresh()->is_blacklisted);
    }

    public function test_unknown_licence_is_ignored_and_rerun_is_idempotent(): void
    {
        $client = $this->makeClient(['enterprise_name' => 'Idempotente Inc.', 'licence_propre_numero' => 1107833420]);

        $this->postJson(self::URI, ['licences' => ['1107833420', '9999999999']])
            ->assertOk()
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.zapped', 1)
            ->assertJsonPath('data.not_found', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.ignored_items.0.index', 1)
            ->assertJsonPath('data.ignored_items.0.licence', '9999999999')
            ->assertJsonPath('data.ignored_items.0.reason', 'not_found');

        $this->postJson(self::URI, ['licences' => ['1107833420']])
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.zapped', 0)
            ->assertJsonPath('data.already_blacklisted', 1)
            ->assertJsonPath('data.failed', 0);

        $this->assertSame(1, Note::count(), 'Un seul journal malgré deux passages.');
        $this->assertTrue((bool) $client->refresh()->is_blacklisted);
    }

    public function test_licence_items_without_any_licence_key_are_failures(): void
    {
        $this->postJson(self::URI, ['licences' => [['municipality' => 'sans licence'], ['Licence' => '   ']]])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.failed', 2)
            ->assertJsonPath('data.errors.0.index', 0)
            ->assertJsonPath('data.errors.0.licence', null);

        $this->postJson(self::URI, ['licence' => ''])->assertStatus(422);
        $this->postJson(self::URI, ['licence' => ['a' => 'b']])->assertStatus(422);
        $this->assertSame(0, Note::count());
    }

    // --------------------------------------------------------- Erreurs partielles

    public function test_partial_failures_keep_the_rest_of_the_batch(): void
    {
        $ok = $this->makeClient(['enterprise_name' => 'OK Inc.']);

        $response = $this->postJson(self::URI, ['names' => [
            ['municipality' => 'sans nom'],   // → échec (aucune clé de nom)
            'OK Inc.',                        // → traité
        ]]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.zapped', 1)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.errors.0.index', 0);

        $this->assertNull($response->json('data.errors.0.name'));
        $this->assertNotEmpty($response->json('data.errors.0.error'));
        $this->assertTrue((bool) $ok->refresh()->is_blacklisted, 'La ligne valide est malgré tout traitée.');
    }

    public function test_batch_where_every_item_fails_reports_success_false(): void
    {
        $this->postJson(self::URI, ['names' => [['foo' => 'bar'], ['baz' => 'qux']]])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.failed', 2);

        $this->assertSame(0, Note::count());
    }

    // -------------------------------------------------------------- Validation

    public function test_invalid_bodies_are_rejected_with_422(): void
    {
        $keep = $this->makeClient(['enterprise_name' => 'Gardé Inc.']);

        $this->postJson(self::URI, ['foo' => 'bar'])->assertStatus(422);
        $this->postJson(self::URI, ['names' => []])->assertStatus(422);
        $this->postJson(self::URI, ['name' => '   '])->assertStatus(422);
        $this->postJson(self::URI, ['name' => 42])->assertStatus(422);
        $this->rawPost('"pas un tableau"')->assertStatus(422);

        // Un corps invalide ne convertit rien.
        $this->assertFalse((bool) $keep->refresh()->is_blacklisted);
    }

    public function test_lot_size_is_bounded(): void
    {
        config(['public_api.max_items' => 1]);

        $a = $this->makeClient(['enterprise_name' => 'A Inc.']);
        $b = $this->makeClient(['enterprise_name' => 'B Inc.']);

        $this->postJson(self::URI, ['names' => ['A Inc.', 'B Inc.']])->assertStatus(422);

        $this->assertFalse((bool) $a->refresh()->is_blacklisted, 'Un lot trop long est refusé intégralement.');
        $this->assertFalse((bool) $b->refresh()->is_blacklisted);
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
