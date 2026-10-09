<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filtre « source » (origine du prospect, `clients.source` — répertoire
 * `sources`, docs/RULES.md §13) :
 *
 *  - **Grande liste admin** (`GET /commercials/clients`) : `?source=` filtre
 *    sur le libellé exact, absent / vide = « Tous » ; la route reste
 *    réservée ADMIN / SUPER_ADMIN (`CheckRole`) ;
 *  - **onglets** : `GET /clients/sources` rend les origines **réellement
 *    présentes** dans la base (distinctes, triées) ;
 *  - **badges de statut** : `GET /clients/overview?source=` borne TOUS les
 *    compteurs à l'onglet actif — invariant « compteur du badge = lignes
 *    rendues » ;
 *  - **panel commercial** (`GET /clients` + `POST clients/reserver`) :
 *    périmètre **verrouillé** sur la source de l'entreprise de l'employé
 *    (`enterprises.source` — `clients.enterprise_id` n'existe plus). Sans
 *    entreprise ou sans source : liste vide, jamais d'ouverture vers toute la
 *    base, et aucun paramètre `source` venu du navigateur n'est écouté.
 */
class GrandeListeSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- Fixtures

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
    }

    private function makeEnterprise(?string $source = 'Angalis'): Enterprise
    {
        return Enterprise::create([
            'name' => 'Bâtiment '.uniqid(),
            'status' => 'ACTIVE',
            'source' => $source,
        ]);
    }

    /** Employé COMERCIAL rattaché à une entreprise (ou sans entreprise). */
    private function makeCommercial(?Enterprise $enterprise = null): User
    {
        $user = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);

        if ($enterprise) {
            Employee::create([
                'user_id' => $user->id,
                'enterprise_id' => $enterprise->id,
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ]);
        }

        return $user;
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Prospect',
            'status' => Client::STATUS_AVAILABLE,
            // Les listes excluent les prospects sans numéro.
            'phone' => '514-555-0100',
        ], $attrs));
    }

    /** Identifiants rendus par `GET commercials/clients` (Grande liste admin). */
    private function adminListIds(User $admin, array $query = []): array
    {
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/commercials/clients?'.http_build_query(
            array_merge(['per_page' => 300], $query)
        ))->assertOk();

        return collect($response->json('data.clients'))->pluck('id')->all();
    }

    /** Identifiants rendus par `GET clients` (liste du commercial). */
    private function commercialListIds(User $commercial, array $query = []): array
    {
        Sanctum::actingAs($commercial);

        $response = $this->getJson('/api/v1/clients?'.http_build_query(
            array_merge(['per_page' => 300], $query)
        ))->assertOk();

        return collect($response->json('data.clients'))->pluck('id')->all();
    }

    // ------------------------------------------- Admin : `?source=` (onglets)

    public function test_admin_list_without_source_returns_every_origin(): void
    {
        $angalis = $this->makeClient(['source' => 'Angalis']);
        $affaire = $this->makeClient(['source' => 'Affaire']);
        $autre = $this->makeClient(['source' => 'Répertoire']);

        $ids = $this->adminListIds($this->makeAdmin());

        $this->assertEqualsCanonicalizing([$angalis->id, $affaire->id, $autre->id], $ids);
    }

    public function test_admin_list_filters_on_the_source_query_parameter(): void
    {
        $angalis = $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Affaire']);

        $ids = $this->adminListIds($this->makeAdmin(), ['source' => 'Angalis']);

        $this->assertCount(2, $ids);
        $this->assertContains($angalis->id, $ids);

        // Onglet « Tous » : paramètre vide → aucun filtre.
        $this->assertCount(3, $this->adminListIds($this->makeAdmin(), ['source' => '']));
    }

    public function test_admin_list_source_filter_matches_the_label_exactly(): void
    {
        $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Affaire']);

        // Répertoire fermé : comparaison exacte, pas de préfixe ni de casse.
        $this->assertSame([], $this->adminListIds($this->makeAdmin(), ['source' => 'ang']));
        $this->assertSame([], $this->adminListIds($this->makeAdmin(), ['source' => 'angalis']));
    }

    public function test_admin_list_source_filter_is_combined_with_the_other_filters(): void
    {
        $this->makeClient(['source' => 'Angalis', 'municipality' => 'Québec']);
        $this->makeClient(['source' => 'Angalis', 'municipality' => 'Lévis']);
        $this->makeClient(['source' => 'Affaire', 'municipality' => 'Québec']);

        $ids = $this->adminListIds($this->makeAdmin(), [
            'source' => 'Angalis',
            'municipality' => 'Québec',
        ]);

        $this->assertCount(1, $ids);
    }

    public function test_commercial_cannot_use_the_admin_grande_liste_endpoint(): void
    {
        $this->makeClient(['source' => 'Angalis']);

        Sanctum::actingAs($this->makeCommercial($this->makeEnterprise()));

        $this->getJson('/api/v1/commercials/clients')
            ->assertForbidden()
            ->assertJsonPath('message', 'Accès non autorisé.');
    }

    // ------------------------------------------------- Onglets : distincts

    public function test_client_sources_endpoint_lists_present_origins_only(): void
    {
        $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Affaire']);
        $this->makeClient(['source' => 'Angalis']);
        // Source du répertoire (`SourceSeeder` / `GET sources`) **sans aucun
        // client** : aucun onglet vide n'en découle.
        Source::firstOrCreate(['name' => 'Répertoire']);

        Sanctum::actingAs($this->makeAdmin());

        $response = $this->getJson('/api/v1/clients/sources')->assertOk();

        $this->assertSame(['Affaire', 'Angalis'], $response->json('data'));
    }

    public function test_client_sources_endpoint_requires_authentication(): void
    {
        $this->makeClient(['source' => 'Affaire']);

        $this->getJson('/api/v1/clients/sources')->assertUnauthorized();
    }

    // ---------------------------------------- Badges : compteurs par source

    public function test_overview_counters_follow_the_active_source_tab(): void
    {
        $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Affaire']);
        $this->makeClient([
            'source' => 'Affaire',
            'status' => Client::STATUS_BLACKLISTED,
            'is_blacklisted' => true,
        ]);

        Sanctum::actingAs($this->makeAdmin());

        // Onglet « Angalis » : total **et** badges bornés à la source.
        $scoped = $this->getJson('/api/v1/clients/overview?source=Angalis')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $scoped['prospects']['system']);
        $this->assertSame(0, $scoped['prospects']['blacklisted']);
        $this->assertSame(1, $scoped['by_display_status'][Client::STATUS_AVAILABLE]);

        // Sans paramètre : périmètre entier (les deux origines + blacklist).
        $global = $this->getJson('/api/v1/clients/overview')->assertOk()->json('data');

        $this->assertSame(3, $global['prospects']['system']);
        $this->assertSame(1, $global['prospects']['blacklisted']);
    }

    // ------------------------------------ Commercial : périmètre verrouillé

    public function test_commercial_list_is_locked_to_the_source_of_his_enterprise(): void
    {
        $enterprise = $this->makeEnterprise('Angalis');
        $commercial = $this->makeCommercial($enterprise);

        $mine = $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Affaire']);

        $ids = $this->commercialListIds($commercial);

        $this->assertCount(2, $ids);
        $this->assertContains($mine->id, $ids);
    }

    public function test_commercial_cannot_widen_his_scope_with_the_source_parameter(): void
    {
        $commercial = $this->makeCommercial($this->makeEnterprise('Angalis'));

        $this->makeClient(['source' => 'Angalis']);
        $affaire = $this->makeClient(['source' => 'Affaire']);

        // `?source=Affaire` envoyé par le navigateur : ignoré, le périmètre
        // vient du serveur.
        $ids = $this->commercialListIds($commercial, ['source' => 'Affaire']);

        $this->assertNotContains($affaire->id, $ids);
    }

    public function test_commercial_without_enterprise_sees_an_empty_list(): void
    {
        $commercial = $this->makeCommercial();

        $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Affaire']);

        $this->assertSame([], $this->commercialListIds($commercial));
    }

    public function test_commercial_whose_enterprise_has_no_source_sees_an_empty_list(): void
    {
        $commercial = $this->makeCommercial($this->makeEnterprise(null));

        $this->makeClient(['source' => 'Angalis']);
        $this->makeClient(['source' => 'Affaire']);

        $this->assertSame([], $this->commercialListIds($commercial));
    }

    public function test_another_enterprise_clients_never_leak_into_the_list(): void
    {
        $mine = $this->makeCommercial($this->makeEnterprise('Affaire'));
        $otherEnterprise = $this->makeEnterprise('Répertoire');
        $other = $this->makeCommercial($otherEnterprise);

        $affaireClient = $this->makeClient(['source' => 'Affaire']);
        $repertoireClient = $this->makeClient(['source' => 'Répertoire']);

        $this->assertSame([$affaireClient->id], $this->commercialListIds($mine));
        $this->assertSame([$repertoireClient->id], $this->commercialListIds($other));
    }

    // ------------------------------------- Commercial : lot réservé borné

    public function test_reservation_lot_is_scoped_to_the_enterprise_source(): void
    {
        $commercial = $this->makeCommercial($this->makeEnterprise('Angalis'));

        // Beaucoup plus de « Affaire » que la capacité du lot : si le
        // périmètre n'était pas appliqué, le lot se remplirait de prospects
        // invisibles dans la liste du commercial.
        $this->makeClient(['source' => 'Angalis']);
        for ($i = 0; $i < 60; $i++) {
            $this->makeClient(['source' => 'Affaire', 'phone' => '514-555-0'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        Sanctum::actingAs($commercial);

        $response = $this->postJson('/api/v1/clients/reserver', ['count' => 50])
            ->assertCreated()
            ->assertJsonPath('data.reserved', 1);

        $reserved = Client::whereIn('id', $response->json('data.clients.*.id'))->pluck('source')->all();

        $this->assertSame(['Angalis'], array_values(array_unique($reserved)));
    }
}
