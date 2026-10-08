<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\Source;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Répertoire `sources` — **liste seule, aucun CRUD** : la seule route est
 * `GET /api/v1/sources` (elle alimente le sélecteur « Source » des modales
 * « Créer / Modifier une entreprise »). Lignes initialisées par
 * `Database\Seeders\SourceSeeder` (« Affaire », « Angalis »).
 */
class SourceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_are_listed_for_any_authenticated_user(): void
    {
        $this->seed(SourceSeeder::class);

        Sanctum::actingAs(User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']));

        $this->getJson('/api/v1/sources')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Affaire')
            ->assertJsonPath('data.1.name', 'Angalis');

        $this->assertCount(2, $this->getJson('/api/v1/sources')->json('data'));
    }

    /** Idempotent : rejouer le seeder ne duplique pas les lignes. */
    public function test_the_source_seeder_is_idempotent(): void
    {
        $this->seed(SourceSeeder::class);
        $this->seed(SourceSeeder::class);

        $this->assertSame(['Affaire', 'Angalis'], Source::orderBy('name')->pluck('name')->all());
    }

    public function test_the_sources_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/sources')->assertUnauthorized();
    }

    /** Aucun CRUD : pas de création / mise à jour / suppression exposée. */
    public function test_no_crud_route_exists_for_sources(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'SUPER_ADMIN', 'status' => 'ACTIVE']));

        // `GET sources` existe → la méthode refusée vaut 405, les routes
        // `sources/{id}` n'existant pas → 404.
        $this->postJson('/api/v1/sources', ['name' => 'Hack'])->assertStatus(405);

        $source = Source::forceCreate(['name' => 'Test']);
        $this->putJson("/api/v1/sources/{$source->id}", ['name' => 'Hack'])->assertStatus(404);
        $this->deleteJson("/api/v1/sources/{$source->id}")->assertStatus(404);
    }

    /** Le sélecteur enregistre le libellé choisi dans `enterprises.source`. */
    public function test_an_enterprise_stores_a_source_chosen_from_the_list(): void
    {
        $this->seed(SourceSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']));

        $created = $this->postJson('/api/v1/entreprises', [
            'name' => 'Avec source',
            'source' => 'Angalis',
        ])->assertCreated()->assertJsonPath('data.entreprise.source', 'Angalis');

        $id = $created->json('data.entreprise.id');

        $this->putJson("/api/v1/entreprises/{$id}", ['source' => 'Affaire'])
            ->assertOk()
            ->assertJsonPath('data.entreprise.source', 'Affaire');

        // Champ omis du payload → source conservée (« Affaire »).
        $this->putJson("/api/v1/entreprises/{$id}", ['name' => 'Renommée'])->assertOk();
        $this->assertSame('Affaire', Enterprise::find($id)->source);
    }
}
