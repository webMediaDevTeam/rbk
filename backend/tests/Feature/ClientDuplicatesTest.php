<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Onglet « Numéros répétés » de la fiche client — endpoint
 * `GET /clients/{id}/duplicates` : renvoie les **autres** clients partageant
 * exactement le même `phone_normalized` (clé de chiffres nationaux posée par
 * `ClientObserver`, insensible au formatage saisi).
 */
class ClientDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Prospect '.uniqid(),
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ], $attrs));
    }

    public function test_returns_other_clients_sharing_the_same_normalized_phone(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        // Trois écritures différentes d'un même numéro → une seule clé `5145550100`.
        $source = $this->makeClient(['phone' => '514-555-0100']);
        $twin = $this->makeClient(['phone' => '(514) 555 0100']);
        $alsoTwin = $this->makeClient(['phone' => '1-514-555-0100']);
        // Variantes avec extension et indicatif : l'extension est retirée, le `1`
        // pays l'est aussi → même clé nationale.
        $extTwin = $this->makeClient(['phone' => '514-555-0100 ext. 402']);
        // Autre numéro : jamais retenu.
        $other = $this->makeClient(['phone' => '438-555-0199']);

        $response = $this->getJson("/api/v1/clients/{$source->id}/duplicates")->assertOk();

        $data = $response->json('data');
        $this->assertSame('5145550100', $data['phone_normalized']);

        $ids = collect($data['duplicates'])->pluck('id')->all();
        sort($ids);
        $expected = [$alsoTwin->id, $extTwin->id, $twin->id];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertSame(3, $data['count']);
        // La fiche courante et celle d'un autre numéro sont exclues.
        $this->assertNotContains($source->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_row_shape_exposes_display_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $source = $this->makeClient(['phone' => '8194186550']);
        $twin = $this->makeClient([
            'phone' => '819-418-6550',
            'name' => 'Dupliqué Test',
            'municipality' => 'Gatineau',
            'source' => 'Affaire',
            // Le tableau affiche répondants / représentant à la place de
            // l'entreprise : les deux champs doivent sortir du payload.
            'respondents' => [['name' => 'Jean Directeur', 'role' => 'Directeur']],
            'representative_name' => 'Marie Représentante',
        ]);

        $row = $this->getJson("/api/v1/clients/{$source->id}/duplicates")
            ->assertOk()
            ->json('data.duplicates.0');

        $this->assertSame($twin->id, $row['id']);
        $this->assertSame('Dupliqué Test', $row['name']);
        $this->assertSame('819-418-6550', $row['phone']);
        $this->assertSame('Gatineau', $row['municipality']);
        $this->assertSame('Affaire', $row['source']);
        $this->assertSame('Marie Représentante', $row['representative_name']);
        $this->assertSame('Jean Directeur', $row['respondents'][0]['name']);
        $this->assertArrayHasKey('display_status', $row);
        $this->assertArrayHasKey('is_blacklisted', $row);
    }

    public function test_client_without_phone_has_no_detectable_duplicate(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $source = $this->makeClient(['phone' => null]);
        // Deux fiches sans numéro ne sont pas considérées comme doublons.
        $this->makeClient(['phone' => null]);

        $response = $this->getJson("/api/v1/clients/{$source->id}/duplicates")->assertOk();

        $this->assertNull($response->json('data.phone_normalized'));
        $this->assertSame(0, $response->json('data.count'));
        $this->assertSame([], $response->json('data.duplicates'));
    }

    public function test_unknown_client_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/clients/00000000-0000-4000-8000-000000000000/duplicates')
            ->assertNotFound();
    }

    // ── « Marquer comme répété » (2.2) ─────────────────────────────

    public function test_mark_repete_links_child_to_parent_and_sets_status(): void
    {
        $user = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        $parent = $this->makeClient(['phone' => '514-555-0100']);
        $child = $this->makeClient(['phone' => '1 (514) 555-0100']);

        $response = $this->postJson("/api/v1/clients/{$child->id}/mark-repete", [
            'parent_client_id' => $parent->id,
        ])->assertOk();

        $this->assertSame(Client::STATUS_REPETE, $response->json('data.status'));
        $this->assertSame($parent->id, $response->json('data.parent_client_id'));

        $child->refresh();
        $this->assertSame(Client::STATUS_REPETE, $child->status);
        $this->assertSame($parent->id, $child->parent_client_id);

        // L'événement est journalisé : ligne fuchsia « Répété » dans
        // l'historique de la fiche enfant, émetteur = l'auteur du clic.
        $note = Note::query()->where('client_id', $child->id)->sole();
        $this->assertSame(Note::TYPE_REPETE, $note->type);
        $this->assertSame($user->id, $note->sender_id);
        $this->assertStringContainsString('répété de', $note->description);
        $this->assertStringContainsString($parent->phone, $note->description);
    }

    public function test_mark_repete_requires_a_valid_parent(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $child = $this->makeClient();

        // Parent manquant → 422 (validation `required`).
        $this->postJson("/api/v1/clients/{$child->id}/mark-repete", [])
            ->assertStatus(422);

        // Parent inexistant → 422 (règle `exists`).
        $this->postJson("/api/v1/clients/{$child->id}/mark-repete", [
            'parent_client_id' => '00000000-0000-4000-8000-000000000000',
        ])->assertStatus(422);

        $this->assertNull($child->refresh()->parent_client_id);
    }

    public function test_mark_repete_refuses_self_link(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $client = $this->makeClient();

        $this->postJson("/api/v1/clients/{$client->id}/mark-repete", [
            'parent_client_id' => $client->id,
        ])->assertStatus(422);

        $this->assertNotSame(Client::STATUS_REPETE, $client->refresh()->status);
    }

    public function test_detail_payload_exposes_parent_summary(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));

        $parent = $this->makeClient(['phone' => '514-555-0100', 'name' => 'Fiche Originale']);
        $child = $this->makeClient(['phone' => '514 555 0100', 'parent_client_id' => $parent->id]);
        $child->update(['status' => Client::STATUS_REPETE]);

        // Fiche du doublon : le bandeau « répétée de … » s'appuie sur `parent`.
        $detail = $this->getJson("/api/v1/commercials/clients/{$child->id}")
            ->assertOk()
            ->json('data.client');

        $this->assertSame($parent->id, $detail['parent_client_id']);
        $this->assertSame('Fiche Originale', $detail['parent']['name']);
    }

    // ── Pastille « num Répétés » de la Grande liste (2.3) ───────────

    public function test_overview_counts_repete_in_display_status(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $this->makeClient(['phone' => '514-555-0111', 'status' => Client::STATUS_REPETE]);
        $this->makeClient(['phone' => '514-555-0112', 'status' => Client::STATUS_REPETE]);
        $this->makeClient(['phone' => '514-555-0113', 'status' => Client::STATUS_AVAILABLE]);

        $response = $this->getJson('/api/v1/clients/overview')->assertOk();

        // `by_display_status` doit exposer la clé REPETE pour alimenter le badge.
        $response->assertJsonPath('data.by_display_status.REPETE', 2);
    }

    public function test_grande_liste_filters_status_repete(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $repete = $this->makeClient(['phone' => '514-555-0120', 'status' => Client::STATUS_REPETE, 'name' => 'Repete Only']);
        $this->makeClient(['phone' => '514-555-0121', 'status' => Client::STATUS_AVAILABLE]);

        $data = $this->getJson('/api/v1/commercials/clients?status=REPETE')->assertOk()->json('data.clients');

        $ids = collect($data)->pluck('id')->all();
        $this->assertContains($repete->id, $ids);
        // Une seule ligne : le fiche AVAILABLE reste hors du filtre REPETE.
        $this->assertCount(1, $ids);
    }

    public function test_mark_repete_all_links_every_unlinked_duplicate(): void
    {
        $user = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        $parent = $this->makeClient(['phone' => '514-555-0200']);
        $dupA = $this->makeClient(['phone' => '(514) 555 0200']);
        $dupB = $this->makeClient(['phone' => '1-514-555-0200']);
        // Déjà rattachée à un autre parent : ne doit **pas** être re-pointée.
        $other = $this->makeClient(['phone' => '438-555-0999']);
        $alreadyLinked = $this->makeClient(['phone' => '514 555 0200', 'parent_client_id' => $other->id]);

        $response = $this->postJson("/api/v1/clients/{$parent->id}/mark-repete-all")->assertOk();
        $this->assertSame(2, $response->json('data.marked'));

        foreach ([$dupA, $dupB] as $dup) {
            $dup->refresh();
            $this->assertSame($parent->id, $dup->parent_client_id);
            $this->assertSame(Client::STATUS_REPETE, $dup->status);

            // Chaque enfant marqué reçoit **sa** note `REPETE` dans l'historique.
            $note = Note::query()->where('client_id', $dup->id)->sole();
            $this->assertSame(Note::TYPE_REPETE, $note->type);
            $this->assertSame($user->id, $note->sender_id);
        }
        // Pas de note pour les fiches non touchées (ni le parent, ni la déjà
        // rattachée) : on ne journalise que les transitions réellement posées.
        $this->assertSame(0, Note::query()->whereIn('client_id', [$parent->id, $alreadyLinked->id])->count());
        // La fiche déjà reliée à un autre parent reste inchangée.
        $this->assertSame($other->id, $alreadyLinked->refresh()->parent_client_id);
        // Le parent n'est jamais son propre doublon.
        $this->assertNull($parent->refresh()->parent_client_id);
    }

    // ── Pastille « num Répétés » de la Grande liste **commerciale** ─────

    public function test_commercial_list_exposes_repete_badge_and_filter(): void
    {
        // Périmètre commercial verrouillé sur la source de l'entreprise.
        $enterprise = Enterprise::create([
            'name' => 'Ent. doublons '.uniqid(),
            'status' => 'ACTIVE',
            'source' => 'Angalis',
        ]);
        $commercial = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Employee::create([
            'user_id' => $commercial->id,
            'enterprise_id' => $enterprise->id,
            'first_name' => 'Jean',
            'last_name' => 'Doublon',
        ]);
        Sanctum::actingAs($commercial);

        $parent = $this->makeClient(['phone' => '514-555-0300', 'source' => 'Angalis']);
        $child = $this->makeClient(['phone' => '(514) 555 0300', 'source' => 'Angalis']);
        $libre = $this->makeClient(['phone' => '418-555-0300', 'source' => 'Angalis']);

        // Marquage en masse depuis la fiche du parent (route tous rôles).
        $this->postJson("/api/v1/clients/{$parent->id}/mark-repete-all")
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $response = $this->getJson('/api/v1/clients?status=REPETE')->assertOk();

        // Le clic sur la pastille rend exactement son compteur : ici,
        // uniquement l'enfant rattaché — ni le parent, ni le libre.
        $this->assertSame([$child->id], array_column($response->json('data.clients'), 'id'));
        $this->assertSame(1, $response->json('data.badges.by_display_status.REPETE'));
        $this->assertSame(3, $response->json('data.badges.prospects.system'));
        $this->assertNotSame($libre->id, $child->id);
    }
}
