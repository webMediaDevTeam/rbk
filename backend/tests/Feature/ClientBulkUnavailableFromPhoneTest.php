<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CallWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Indisponibilité en masse **par numéro de téléphone**
 * (`Client::bulkUnavailableFromPhone()`) et détection de numéro
 * (`Client::normalizePhone()`).
 *
 * Fil conducteur : les formes `819-418-6550`, `8194186550`,
 * `+1819-418-6550`, `+18194186550`, `+1-819-418-6550`… désignent un seul
 * et même numéro des deux côtés ; chaque ligne visée passe en
 * `UNAVAILABLE` avec `returned_at = now + 3 mois` (geste NO, durée portée
 * par `CallWorkflowService::NON_BLOCK_MONTHS`), rappels annulés,
 * réservations conservées, note `NOTE` émise par `SYSTEM`.
 *
 * Rapport consolidé : `processed` (succès) / `blocked` / `ignored` /
 * `failed` — **un numéro introuvable est ignoré, jamais une erreur**, et le
 * lot est ré-exécutable (idempotent). Une ligne en liste noire n'est jamais
 * rétrogradée.
 */
class ClientBulkUnavailableFromPhoneTest extends TestCase
{
    use RefreshDatabase;

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

    // ------------------------------------------------- Détection du numéro

    public function test_all_phone_formats_detect_the_same_number(): void
    {
        $formats = [
            '819-418-6550',
            '8194186550',
            '(819) 418 6550',
            '819.418.6550',
            '+1819-418-6550',
            '+18194186550',
            '+1-819-418-6550',
            '1 819 418 6550',
            '1-819-418-6550',
            '819 418 6550 ext. 5417',   // l'extension n'appartient pas au numéro
        ];

        foreach ($formats as $format) {
            $this->assertSame('8194186550', Client::normalizePhone($format), $format);
        }

        // Champs vides / illisibles : aucune clé détectée.
        $this->assertNull(Client::normalizePhone('sans numéro'));
        $this->assertNull(Client::normalizePhone('   '));
        $this->assertNull(Client::normalizePhone(null));
        $this->assertNull(Client::normalizePhone(['819-418-6550']));
    }

    // ---------------------------------------------------------- Blocage 3 mois

    public function test_bulk_blocks_client_with_returned_at_in_three_months(): void
    {
        $target = $this->makeClient(['phone' => '819-418-6550']);
        $other = $this->makeClient(['phone' => '514-353-5820']);

        // Saisie dans une toute autre forme que la colonne stockée.
        $report = Client::bulkUnavailableFromPhone(['+1-819-418-6550']);

        $this->assertSame(1, $report['received']);
        $this->assertSame(1, $report['processed']);
        $this->assertSame(1, $report['matched']);
        $this->assertSame(1, $report['blocked']);
        $this->assertSame(0, $report['ignored']);
        $this->assertSame(0, $report['not_found']);
        $this->assertSame(0, $report['failed']);
        $this->assertSame('+1-819-418-6550', $report['blocked_items'][0]['phone']);
        $this->assertSame(1, $report['blocked_items'][0]['blocked']);

        // Geste NO : UNAVAILABLE + retour dans 3 mois.
        $target->refresh();
        $this->assertSame(Client::STATUS_UNAVAILABLE, $target->status);
        $this->assertEqualsWithDelta(
            now()->addMonths(CallWorkflowService::NON_BLOCK_MONTHS)->timestamp,
            $target->returned_at->timestamp,
            5
        );

        // Ligne non visée : intacte.
        $other->refresh();
        $this->assertSame(Client::STATUS_AVAILABLE, $other->status);
        $this->assertNull($other->returned_at);

        // Journal `NOTE`, émetteur `SYSTEM` (pas d'utilisateur côté API).
        $note = Note::where('client_id', $target->id)->firstOrFail();
        $this->assertSame(Note::TYPE_NOTE, $note->type);
        $this->assertSame(Note::SENDER_SYSTEM, $note->sender_id);
    }

    public function test_stored_phone_is_detected_in_any_format(): void
    {
        // Colonne stockée sous trois formes différentes d'un même numéro.
        $dashed = $this->makeClient(['phone' => '819-418-6550']);
        $bare = $this->makeClient(['phone' => '8194186550']);
        $country = $this->makeClient(['phone' => '1 819-418-6550']);
        $withExt = $this->makeClient(['phone' => '819-418-6550 ext. 5417']);

        $report = Client::bulkUnavailableFromPhone(['8194186550']);

        $this->assertSame(4, $report['matched']);
        $this->assertSame(4, $report['blocked']);

        foreach ([$dashed, $bare, $country, $withExt] as $client) {
            $this->assertSame(Client::STATUS_UNAVAILABLE, $client->refresh()->status);
        }
    }

    public function test_every_row_sharing_the_number_is_blocked(): void
    {
        $first = $this->makeClient(['phone' => '+18194186550']);
        $twin = $this->makeClient(['phone' => '819-418-6550']);
        $other = $this->makeClient(['phone' => '418-555-1212']);

        $report = Client::bulkUnavailableFromPhone(['+1-819-418-6550']);

        $this->assertSame(2, $report['matched']);
        $this->assertSame(2, $report['blocked']);
        $this->assertSame(0, $report['not_found']);

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

        $report = Client::bulkUnavailableFromPhone(['819-418-6550']);
        $this->assertSame(1, $report['blocked']);

        // Sinon le cron des rappels expirés ré-appliquerait son blocage 21 j
        // et écraserait le retour à 3 mois.
        $this->assertSame(0, Rappel::where('client_id', $client->id)->count(), 'Rappels annulés.');
        // L'indisponibilité ne supprime jamais l'historique.
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    // ------------------------------------------------------ Rapport consolidé

    public function test_report_counts_success_blocked_ignored_and_errors(): void
    {
        $this->makeClient(['phone' => '819-418-6550']);   // → bloqué
        $this->makeClient([                               // → ignoré (blacklisté)
            'phone' => '418-555-1212',
            'status' => Client::STATUS_BLACKLISTED,
            'is_blacklisted' => true,
        ]);

        $report = Client::bulkUnavailableFromPhone([
            '819-418-6550',        // → bloqué
            '418-555-1212',        // → ignoré (blacklisted, jamais rétrogradé)
            '514-555-0000',        // → ignoré (not_found), **pas** une erreur
            ['municipality' => 'x'], // → échec (aucune clé de téléphone)
            'pas-un-numéro',       // → échec (aucun chiffre)
        ]);

        $this->assertSame(5, $report['received']);
        $this->assertSame(3, $report['processed']);   // succès
        $this->assertSame(1, $report['blocked']);
        $this->assertSame(2, $report['ignored']);
        $this->assertSame(1, $report['not_found']);
        $this->assertSame(1, $report['blacklisted']);
        $this->assertSame(0, $report['already_unavailable']);
        $this->assertSame(2, $report['failed']);
        $this->assertSame('blacklisted', $report['ignored_items'][0]['reason']);
        $this->assertSame('not_found', $report['ignored_items'][1]['reason']);
        $this->assertSame(3, $report['errors'][0]['index']);
        $this->assertSame(4, $report['errors'][1]['index']);
        $this->assertNull($report['errors'][0]['phone']);
    }

    // ------------------------------------------------------ Idempotence / formes

    public function test_already_unavailable_rows_are_counted_and_not_rewritten(): void
    {
        $client = $this->makeClient([
            'phone' => '819-418-6550',
            'status' => Client::STATUS_UNAVAILABLE,
            'returned_at' => now()->addDays(10),
        ]);
        $client->forceFill(['updated_at' => now()->subDay()])->save();
        $frozen = $client->refresh()->updated_at;

        $report = Client::bulkUnavailableFromPhone(['819-418-6550']);

        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['blocked']);
        $this->assertSame(1, $report['ignored']);
        $this->assertSame(1, $report['already_unavailable']);
        $this->assertSame('already_unavailable', $report['ignored_items'][0]['reason']);

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

        $report = Client::bulkUnavailableFromPhone(['819-418-6550']);

        $this->assertSame(1, $report['matched']);
        $this->assertSame(0, $report['blocked']);
        $this->assertSame(1, $report['blacklisted']);

        $client->refresh();
        $this->assertSame(Client::STATUS_BLACKLISTED, $client->status);
        $this->assertTrue((bool) $client->is_blacklisted);
        $this->assertNull($client->returned_at, 'La liste noire ne gagne jamais de compte à rebours.');
    }

    public function test_rerun_is_idempotent_and_ignores_unknown_numbers(): void
    {
        $client = $this->makeClient(['phone' => '819-418-6550']);

        // 1er passage.
        $first = Client::bulkUnavailableFromPhone(['819-418-6550', '514-555-0000']);
        $this->assertSame(2, $first['processed']);
        $this->assertSame(1, $first['blocked']);
        $this->assertSame(1, $first['ignored']);
        $this->assertSame(1, $first['not_found']);
        $this->assertSame(0, $first['failed']);

        $this->assertSame(Client::STATUS_UNAVAILABLE, $client->refresh()->status);

        // 2e passage : rien à bloquer, tout est ignoré, **zéro erreur**.
        $second = Client::bulkUnavailableFromPhone(['819-418-6550', '514-555-0000']);
        $this->assertSame(2, $second['processed']);
        $this->assertSame(0, $second['blocked']);
        $this->assertSame(2, $second['ignored']);
        $this->assertSame(1, $second['not_found']);
        $this->assertSame(1, $second['already_unavailable']);
        $this->assertSame(0, $second['failed']);

        $this->assertSame(1, Note::count(), 'Un seul journal malgré deux passages.');
    }

    public function test_bare_string_and_object_items_are_both_accepted(): void
    {
        $alpha = $this->makeClient(['phone' => '819-418-6550']);
        $beta = $this->makeClient(['phone' => '418-555-1212']);

        // Chaîne nue.
        Client::bulkUnavailableFromPhone(['819-418-6550']);
        // Objet (clé française / anglaise).
        Client::bulkUnavailableFromPhone([
            ['phone' => '418-555-1212'],
            ['Téléphone' => '819-418-6550'],  // déjà bloqué : ignoré, pas d'erreur
        ]);

        $this->assertSame(Client::STATUS_UNAVAILABLE, $alpha->refresh()->status);
        $this->assertSame(Client::STATUS_UNAVAILABLE, $beta->refresh()->status);
    }

    public function test_partial_failures_keep_the_rest_of_the_batch(): void
    {
        $ok = $this->makeClient(['phone' => '819-418-6550']);

        $report = Client::bulkUnavailableFromPhone([
            ['municipality' => 'sans numéro'],   // → échec (aucune clé de téléphone)
            '819-418-6550',                      // → traité
        ]);

        $this->assertSame(1, $report['blocked']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, $report['errors'][0]['index']);
        $this->assertNotEmpty($report['errors'][0]['error']);

        $this->assertSame(Client::STATUS_UNAVAILABLE, $ok->refresh()->status, 'La ligne valide est malgré tout traitée.');
    }
}
