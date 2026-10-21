<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /commercials/stats` — compteurs par employé affichés en puces sur la
 * page « Employés » (Clients / BV / Rappel / Oui). Le chiffre doit égaler les
 * lignes de la destination cliquée : historique de `GET commercials/{id}`
 * filtré par badge (périmètre = clients appelés par CET employé).
 */
class CommercialsStatsTest extends TestCase
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

    /** Client appelé par l'employé donné (une note d'issue = « traité »). */
    private function calledClient(User $sender, string $noteType = Note::TYPE_NO, array $attrs = []): Client
    {
        $client = Client::create(array_merge([
            'name' => 'Client '.$sender->id.' '.$noteType,
            'status' => Client::STATUS_RESERVED,
            'phone' => '514-555-'.random_int(1000, 9999),
        ], $attrs));

        Note::create([
            'client_id' => $client->id,
            'sender_id' => $sender->id,
            'type' => $noteType,
        ]);

        return $client;
    }

    private function reserve(Client $client, User $sender, string $status): Reservation
    {
        return Reservation::create([
            'client_id' => $client->id,
            'comercial_id' => $sender->id,
            'status' => $status,
        ]);
    }

    public function test_stats_are_scoped_per_employee_and_match_history_semantics(): void
    {
        Sanctum::actingAs($this->admin);

        $other = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);

        // Périmètre du commercial : 3 clients appelés (dont 1 par OUI, donc
        // 2 notes YES pour tester « oui = notes, pas clients distincts »).
        $this->calledClient($this->commercial, Note::TYPE_NO, ['status' => Client::STATUS_AVAILABLE]);

        $bv = $this->calledClient($this->commercial, Note::TYPE_BV);
        $this->reserve($bv, $this->commercial, Reservation::STATUS_BV_VOICEMAIL);

        $callBack = $this->calledClient($this->commercial, Note::TYPE_CALL_BACK);
        $this->reserve($callBack, $this->commercial, Reservation::STATUS_CALL_BACK);

        // BV blacklisté : hors filtre `filterByReservationStatuses` (compte
        // BV = 1 malgré le statut courant BV_VOICEMAIL).
        $blacklistedBv = $this->calledClient($this->commercial, Note::TYPE_BV);
        $this->reserve($blacklistedBv, $this->commercial, Reservation::STATUS_BV_VOICEMAIL);
        $blacklistedBv->update(['status' => Client::STATUS_BLACKLISTED, 'is_blacklisted' => true]);

        $oui = $this->calledClient($this->commercial, Note::TYPE_YES);
        Note::create(['client_id' => $oui->id, 'sender_id' => $this->commercial->id, 'type' => Note::TYPE_YES]);

        // Client appelé par un AUTRE employé : jamais mélangé.
        $this->calledClient($other, Note::TYPE_NO);

        $response = $this->getJson('/api/v1/commercials/stats')->assertOk();

        $stats = "data.stats.{$this->commercial->id}";
        $response
            ->assertJsonPath("{$stats}.clients", 5)
            ->assertJsonPath("{$stats}.bv", 1)
            ->assertJsonPath("{$stats}.rappel", 1)
            ->assertJsonPath("{$stats}.oui", 2);

        $response->assertJsonPath("data.stats.{$other->id}.clients", 1);
        $response->assertJsonPath("data.stats.{$other->id}.oui", 0);
        // Un ADMIN n'est pas un rôle contraint : absent de la carte.
        $response->assertJsonMissingPath("data.stats.{$this->admin->id}");
    }

    public function test_operator_rows_are_returned_without_reservation_outcomes(): void
    {
        Sanctum::actingAs($this->admin);

        $operator = User::factory()->create(['role' => 'OPERATOR', 'status' => 'ACTIVE']);
        $this->calledClient($operator, Note::TYPE_INFO);

        $this->getJson('/api/v1/commercials/stats')
            ->assertOk()
            ->assertJsonPath("data.stats.{$operator->id}.clients", 1)
            ->assertJsonPath("data.stats.{$operator->id}.bv", 0)
            ->assertJsonPath("data.stats.{$operator->id}.rappel", 0);
    }

    public function test_endpoint_is_restricted_to_managers(): void
    {
        Sanctum::actingAs($this->commercial);

        $this->getJson('/api/v1/commercials/stats')->assertForbidden();
    }
}
