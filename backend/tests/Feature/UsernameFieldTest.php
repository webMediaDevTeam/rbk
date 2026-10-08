<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Nom d'utilisateur » — colonne unique `users.username`
 * (migration 2026_10_08_100000) + contrôle en temps réel de la modale
 * employé (`GET users/username-available`).
 *
 *  - création / édition : valeur normalisée en minuscules, syntaxe
 *    `^[a-z0-9._-]{3,100}$`, unicité refusée en 422 ;
 *  - l'endpoint `username-available` répond `available` (libre / pris),
 *    exclut l'utilisateur édité et refuse un COMERCIAL (403) ;
 *  - la route est déclarée **avant** `GET users/{id}` : « username-
 *    available » n'est pas avalée par le paramètre `{id}`.
 */
class UsernameFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $enterpriseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);
        $enterprise = Enterprise::forceCreate(['name' => 'Username Enterprise']);
        $this->enterpriseId = $enterprise->id;
    }

    /** Payload de création minimal (mot de passe → aucun e-mail de vérif). */
    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'identifiant@demo.test',
            'mot_de_passe' => 'secret123',
            'mot_de_passe_confirmation' => 'secret123',
            'role' => 'COMERCIAL',
            'first_name' => 'Ugo',
            'last_name' => 'Namen',
            'enterprise_id' => $this->enterpriseId,
            'username' => 'ugo.namen',
        ], $overrides);
    }

    // ------------------------------------------------------ création / édition

    public function test_create_persists_the_username(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->createPayload())
            ->assertCreated()
            ->assertJsonPath('data.utilisateur.username', 'ugo.namen');

        $this->assertSame(
            'ugo.namen',
            User::where('email', 'identifiant@demo.test')->first()->username
        );
    }

    public function test_username_is_normalized_to_lowercase(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->createPayload(['username' => '  Ugo.NAMEN  ']))
            ->assertCreated()
            ->assertJsonPath('data.utilisateur.username', 'ugo.namen');
    }

    public function test_a_duplicated_username_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->createPayload())->assertCreated();

        $this->postJson('/api/v1/users', $this->createPayload([
            'email' => 'identifiant2@demo.test',
            'username' => 'Ugo.Namen', // casse différente, même valeur normalisée
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    public function test_an_invalid_username_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->createPayload(['username' => 'nom avec espace']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);

        $this->postJson('/api/v1/users', $this->createPayload([
            'email' => 'identifiant2@demo.test',
            'username' => 'ab', // 3 caractères minimum
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    public function test_update_can_rename_and_clear_the_username(): void
    {
        $commercial = $this->makeCommercial(['username' => 'ancien.nom']);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/users/{$commercial->id}", ['username' => 'Nouveau.Nom'])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.username', 'nouveau.nom');
        $this->assertSame('nouveau.nom', $commercial->fresh()->username);

        // Effacement possible (colonne nullable) : null explicite.
        $this->putJson("/api/v1/users/{$commercial->id}", ['username' => null])
            ->assertOk()
            ->assertJsonPath('data.utilisateur.username', null);
        $this->assertNull($commercial->fresh()->username);
    }

    public function test_update_rejects_a_username_taken_by_another_user(): void
    {
        $taken = $this->makeCommercial(['username' => 'deja.pris']);
        $commercial = $this->makeCommercial();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/users/{$commercial->id}", ['username' => 'Deja.Pris'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);

        $this->assertSame('deja.pris', $taken->fresh()->username);
    }

    // ------------------------------------------------- disponibilité en direct

    public function test_availability_reports_free_and_taken_usernames(): void
    {
        $this->makeCommercial(['username' => 'deja.pris']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/users/username-available?username=deja.pris')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.valid', true);

        // Normalisation identique à celle de l'écriture (minuscules).
        $this->getJson('/api/v1/users/username-available?username=DEJA.PRIS')
            ->assertOk()
            ->assertJsonPath('data.available', false);

        $this->getJson('/api/v1/users/username-available?username=tout.a.fait.libre')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.valid', true);
    }

    public function test_availability_excludes_the_user_being_edited(): void
    {
        $commercial = $this->makeCommercial(['username' => 'moi.meme']);
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/users/username-available?username=moi.meme&id={$commercial->id}")
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }

    public function test_availability_flags_a_badly_formed_username(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/users/username-available?username=ab')
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.available', false);

        $this->getJson('/api/v1/users/username-available?username=')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    public function test_availability_is_forbidden_for_a_commercial(): void
    {
        $commercial = $this->makeCommercial();
        Sanctum::actingAs($commercial);

        $this->getJson('/api/v1/users/username-available?username=tout.a.fait.libre')
            ->assertForbidden();
    }

    // --------------------------------------------------------------- utilitaires

    private function makeCommercial(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
        ], $attrs));
    }
}
