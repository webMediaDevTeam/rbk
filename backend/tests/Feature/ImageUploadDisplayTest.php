<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Téléversement **et** affichage des images de l'application : la photo d'un
 * employé (`users.avatar` + `employees.image_dp`, disque `public`, répertoire
 * `avatars`) et le logo d'une entreprise (`enterprises.logo`, répertoire
 * `logos`).
 *
 * Le contrat est double — les deux volets sont testés ensemble parce qu'ils ne
 * forment qu'une seule fonctionnalité côté UI (la modale `AvatarUpdateModal`
 * envoie, `UserAvatar` affiche) :
 *
 *  1. **téléversement** — le fichier est écrit sur le disque `public` sous un nom
 *     UUID (le nom d'origine ne traverse jamais la frontière), l'ancien fichier
 *     est supprimé, et les colonnes porteuses pointent sur le nouveau nom ;
 *  2. **affichage** — les projections (`GET /users`, `GET /commercials/{id}`,
 *     `GET /entreprises`, `GET /entreprises/{id}`) exposent l'URL
 *     `/storage/{répertoire}/{nom}` correspondante, sinon l'image téléversée
 *     n'apparaît nulle part malgré un envoi réussi.
 *
 * Sont vérifiées aussi : la synchronisation `users.avatar` ⇄ `employees.image_dp`
 * (la modale des deux listes lit indifféremment l'une ou l'autre URL), le
 * périmètre de `UserController::canAct` (un pair ne change pas la photo d'un
 * autre, soi oui — page Profil), et le garde de rôle sur le logo
 * (`ADMIN` / `SUPER_ADMIN` seuls).
 *
 * `Storage::fake('public')` : aucun octet n'atteint `storage/app/public`, le
 * répertoire réellement servi par le dev.
 */
class ImageUploadDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $commercial;

    private User $operator;

    private Enterprise $enterprise;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);

        $this->enterprise = Enterprise::create([
            'name' => 'Construction Alpha',
            'email' => 'contact@alpha.test',
            'phone' => '514-555-0100',
            'status' => 'ACTIVE',
            'source' => 'web',
        ]);

        $this->commercial = User::factory()->create([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.test',
        ]);

        Employee::create([
            'user_id' => $this->commercial->id,
            'enterprise_id' => $this->enterprise->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '514-555-0111',
        ]);

        // Opérateur : compte sans fiche `employees` — l'avatar ne vit que sur
        // `users`, et c'est le chemin d'écriture par défaut du contrôleur.
        $this->operator = User::factory()->create([
            'role' => 'OPERATOR',
            'status' => 'ACTIVE',
            'first_name' => 'Paul',
            'last_name' => 'Op',
            'email' => 'paul.op@example.test',
        ]);
    }

    /** Nom de fichier attendu : un UUID suivi de l'extension. */
    private function storedName(string $filename, string $extension): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.'.$extension.'$/',
            $filename,
            'Le nom d\'origine du fichier ne doit jamais resservir sur le disque.'
        );
    }

    /**
     * Un refus (validation, périmètre) ne doit rien laisser sur le disque —
     * sinon la corbeille `storage/app/public` grossit à chaque essai raté.
     */
    private function assertNothingStored(string $directory): void
    {
        $this->assertSame([], Storage::disk('public')->files($directory));
    }

    /*
    |--------------------------------------------------------------------------
    | Photo d'employé — téléversement
    |--------------------------------------------------------------------------
    */

    public function test_employee_photo_upload_writes_the_employee_record_and_the_account(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/users/{$this->commercial->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo-jane.png'),
        ])->assertOk()->assertJsonPath('success', true);

        $filename = $response->json('data.avatar');
        $this->storedName($filename, 'png');

        Storage::disk('public')->assertExists("avatars/{$filename}");

        // Un COMERCIAL porteur d'une fiche `employees` écrit sur `image_dp`, et
        // `users.avatar` est aligné sur le **même** nom : les deux listes
        // (`profil.image_dp_url` / `avatar_url`) affichent donc le même fichier.
        $this->assertSame($filename, $this->commercial->fresh()->employee->image_dp);
        $this->assertSame($filename, $this->commercial->fresh()->avatar);

        // L'URL est **relative** : elle doit être relue sur l'origine du
        // navigateur (proxy Vite en dev, nginx en prod), jamais sur le port de
        // l'API — c'est ce qui cassait l'affichage des images téléversées.
        $this->assertSame("/storage/avatars/{$filename}", $response->json('data.avatar_url'));
        $this->assertStringNotContainsString('://', $response->json('data.avatar_url'));
    }

    public function test_uploading_a_photo_replaces_the_previous_file(): void
    {
        Storage::disk('public')->put('avatars/ancienne.png', 'ancienne image');
        $this->commercial->employee->update(['image_dp' => 'ancienne.png']);
        $this->commercial->update(['avatar' => 'ancienne.png']);

        Sanctum::actingAs($this->admin);

        $filename = $this->postJson("/api/v1/users/{$this->commercial->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('nouvelle.png'),
        ])->assertOk()->json('data.avatar');

        Storage::disk('public')->assertExists("avatars/{$filename}");
        Storage::disk('public')->assertMissing('avatars/ancienne.png');
    }

    public function test_account_without_an_employee_record_stores_the_avatar_on_the_user(): void
    {
        Sanctum::actingAs($this->admin);

        $filename = $this->postJson("/api/v1/users/{$this->operator->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo-op.png'),
        ])->assertOk()->json('data.avatar');

        $this->assertNull($this->operator->fresh()->employee);
        $this->assertSame($filename, $this->operator->fresh()->avatar);
        Storage::disk('public')->assertExists("avatars/{$filename}");
    }

    public function test_photo_upload_rejects_a_non_image_and_an_oversized_file(): void
    {
        Sanctum::actingAs($this->admin);

        // Une image est exigée…
        $this->postJson("/api/v1/users/{$this->commercial->id}/avatar", [
            'avatar' => UploadedFile::fake()->create('note.txt', 8, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');

        // … de 2 Mo maximum (garde côté serveur de la modale, qui annonce la
        // même limite dans son aide).
        $this->postJson("/api/v1/users/{$this->commercial->id}/avatar", [
            'avatar' => UploadedFile::fake()->create('trop-lourd.png', 3_000, 'image/png'),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');

        // Un refus ne doit rien laisser sur le disque.
        $this->assertNothingStored('avatars');
        $this->assertNull($this->commercial->fresh()->avatar);
    }

    /*
    |--------------------------------------------------------------------------
    | Photo d'employé — périmètre
    |--------------------------------------------------------------------------
    */

    public function test_a_commercial_cannot_change_a_peer_photo_but_may_change_its_own(): void
    {
        $peer = User::factory()->create(['role' => 'COMERCIAL', 'status' => 'ACTIVE']);
        Sanctum::actingAs($peer);

        // `canAct(..., 'update')` exige un rang strictement supérieur : un pair
        // est refusé…
        $this->postJson("/api/v1/users/{$this->commercial->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('intrus.png'),
        ])->assertForbidden();

        // … alors que la page Profil (soi-même) doit passer.
        $this->postJson("/api/v1/users/{$peer->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('ma-photo.png'),
        ])->assertOk();

        Storage::disk('public')->assertMissing('avatars/intrus.png');
    }

    public function test_an_operator_cannot_reach_the_avatar_route(): void
    {
        // Le groupe de routes accepte OPERATOR (droit de gérer **son** avatar),
        // mais le périmètre reste verrouillé par `canAct` : un opérateur ne
        // change pas la photo d'un commercial.
        Sanctum::actingAs($this->operator);

        $this->postJson("/api/v1/users/{$this->commercial->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('op-sur-jane.png'),
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Photo d'employé — affichage
    |--------------------------------------------------------------------------
    */

    public function test_the_uploaded_photo_is_displayed_by_the_list_and_the_detail(): void
    {
        Sanctum::actingAs($this->admin);

        $filename = $this->postJson("/api/v1/users/{$this->commercial->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo-jane.png'),
        ])->assertOk()->json('data.avatar');

        $expected = "/storage/avatars/{$filename}";

        // Ligne de la page « Employés » : les deux URL de la même image.
        $row = collect($this->getJson('/api/v1/users?role=COMERCIAL')->assertOk()->json('data.utilisateurs'))
            ->firstWhere('id', $this->commercial->id);
        $this->assertSame($expected, $row['avatar_url']);
        $this->assertSame($expected, $row['profil']['image_dp_url']);

        // Fiche employé : compte + section « Détails employé ».
        $detail = $this->getJson("/api/v1/commercials/{$this->commercial->id}")->assertOk();
        $this->assertSame($expected, $detail->json('user.avatar_url'));
        $this->assertSame($expected, $detail->json('user.profil.image_dp_url'));
        $this->assertSame($filename, $detail->json('employee.image_dp'));
        $this->assertSame($expected, $detail->json('employee.image_dp_url'));
    }

    public function test_an_account_without_image_yields_no_url_rather_than_a_broken_one(): void
    {
        Sanctum::actingAs($this->admin);

        $row = collect($this->getJson('/api/v1/users?role=COMERCIAL')->assertOk()->json('data.utilisateurs'))
            ->firstWhere('id', $this->commercial->id);

        // `null` et non `"/storage/avatars/"` : l'UI retombe sur les initiales.
        $this->assertNull($row['avatar_url']);
        $this->assertNull($row['profil']['image_dp_url']);
    }

    /*
    |--------------------------------------------------------------------------
    | Logo d'entreprise — téléversement et affichage
    |--------------------------------------------------------------------------
    */

    public function test_enterprise_logo_upload_is_stored_and_displayed(): void
    {
        Storage::disk('public')->put('logos/logo-alpha.png', 'ancien logo');
        $this->enterprise->update(['logo' => 'logo-alpha.png']);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/entreprises/{$this->enterprise->id}/logo", [
            'logo' => UploadedFile::fake()->image('nouveau logo.png', 64, 64),
        ])->assertOk()->assertJsonPath('success', true);

        $filename = $response->json('data.logo');
        $this->storedName($filename, 'png');

        Storage::disk('public')->assertExists("logos/{$filename}");
        Storage::disk('public')->assertMissing('logos/logo-alpha.png');
        $this->assertSame($filename, $this->enterprise->fresh()->logo);

        $expected = "/storage/logos/{$filename}";
        $this->assertSame($expected, $response->json('data.logo_url'));
        // L'URL est rendue deux fois (`logo_url` et `avatar_url`) : les
        // composants d'avatar génériques consomment `avatar_url` sans savoir
        // qu'il s'agit d'un logo.
        $this->assertSame($expected, $response->json('data.avatar_url'));
        $this->assertSame($expected, $response->json('data.entreprise.logo_url'));

        // Liste et fiche entreprise.
        $this->getJson('/api/v1/entreprises')
            ->assertOk()
            ->assertJsonPath('data.entreprises.0.logo_url', $expected);
        $this->getJson("/api/v1/entreprises/{$this->enterprise->id}")
            ->assertOk()
            ->assertJsonPath('data.entreprise.logo_url', $expected);

        // Et le logo est visible depuis la fiche de l'employé de l'entreprise
        // (deux projections : l'onglet détail et le `profil` des listes).
        $detail = $this->getJson("/api/v1/commercials/{$this->commercial->id}")->assertOk();
        $this->assertSame($expected, $detail->json('entreprise.logo_url'));
        $this->assertSame($expected, $detail->json('user.profil.entreprise.logo_url'));
    }

    public function test_logo_upload_requires_an_image(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/entreprises/{$this->enterprise->id}/logo", [
            'logo' => UploadedFile::fake()->create('facture.pdf', 8, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->assertNothingStored('logos');
        $this->assertNull($this->enterprise->fresh()->logo);
    }

    public function test_logo_upload_is_restricted_to_managers(): void
    {
        foreach (['COMERCIAL' => $this->commercial, 'OPERATOR' => $this->operator] as $role => $actor) {
            Sanctum::actingAs($actor);

            $this->postJson("/api/v1/entreprises/{$this->enterprise->id}/logo", [
                'logo' => UploadedFile::fake()->image("logo-{$role}.png"),
            ])->assertForbidden("Le téléversement d'un logo doit rester réservé aux gestionnaires ({$role} refusé).");
        }

        $this->assertNothingStored('logos');
    }

    public function test_logo_upload_on_an_unknown_enterprise_is_a_404(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/entreprises/does-not-exist/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertNotFound();
    }
}
