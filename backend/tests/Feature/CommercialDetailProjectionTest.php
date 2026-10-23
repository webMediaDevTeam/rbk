<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrat de la projection de `GET /commercials/{id}` — l'onglet
 * « Détails employé » de la fiche.
 *
 * Trois garanties verrouillées ici :
 *  1. **complétude** — la fiche sert toutes les données du compte (identité,
 *     réglages, téléphonie RingCentral, entreprise), y compris les colonnes que
 *     la modale n'écrit pas (numéro d'extension, numéros du poste) ;
 *  2. **non-fuite** — la projection est explicite : `password_hash` et le jeton
 *     `verification_token` ne sortent jamais (seul l'horodatage d'envoi est utile
 *     à la fiche) ;
 *  3. **forme partagée** — `user.profil` est *identique* à celui des lignes de
 *     la page « Employés » (`UserController::formatUser()`). Les deux réponses
 *     pré-remplissent `CommercialUpdateModal` : si elles dérivent, la modale
 *     enregistrée remet silencieusement des champs à vide.
 *
 * La dérogation hors zones est rendue à son **état effectif**
 * (`User::isGpsBypassActive()` : drapeau posé *et* échéance de 24 h non atteinte),
 * donc jamais la fiche ne promet un accès que le serveur refuserait.
 */
class CommercialDetailProjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $commercial;

    private Enterprise $enterprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'ADMIN', 'status' => 'ACTIVE']);

        $this->enterprise = Enterprise::create([
            'name' => 'Construction Alpha',
            'email' => 'contact@alpha.test',
            'tax_number' => '1234567890RQ0001',
            'phone' => '514-555-0100',
            'address' => '1 rue des Chantiers, Montréal',
            'status' => 'ACTIVE',
            'source' => 'web',
            'logo' => 'logo-alpha.png',
        ]);

        // Compte porteur de secrets : la projection ne doit rien en laisser passer.
        $this->commercial = User::factory()->create([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
            'username' => 'jdoe',
            'email' => 'jane.doe@example.test',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '514-555-0111',
            'has_permission' => true,
            'avatar' => 'jane.png',
            'email_verified_at' => now()->subDays(3),
            'verification_token' => 'token-a-ne-jamais-rendre',
            'verification_sent_at' => now(),
        ]);

        Employee::create([
            'user_id' => $this->commercial->id,
            'enterprise_id' => $this->enterprise->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '514-555-0111',
            'image_dp' => 'jane-fiche.png',
            'additional_info' => 'Apte aux chantiers hors semaine.',
            'ringcentral_device_id' => 'device-42',
            'ringcentral_extension_id' => 'ext-7',
            'ringcentral_extension_number' => '7042',
            'ringcentral_from_number' => '+15145550111',
            'ringcentral_phone_numbers' => ['+15145550111', '+14385550111'],
            'ringcentral_synced_at' => now()->subHour(),
        ]);
    }

    /** Fiche de l'employé, comme la page la demande. */
    private function detail(): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson("/api/v1/commercials/{$this->commercial->id}")->assertOk();
    }

    /** Ligne de la page « Employés » (`GET /users`) pour le même compte. */
    private function listRow(): array
    {
        Sanctum::actingAs($this->admin);

        $rows = $this->getJson('/api/v1/users?role=COMERCIAL')
            ->assertOk()
            ->json('data.utilisateurs');

        $row = collect($rows)->firstWhere('id', $this->commercial->id);

        $this->assertNotNull($row, 'La page Employés doit lister le commercial.');

        return $row;
    }

    // ==================================================================
    // 1. Complétude
    // ==================================================================

    public function test_detail_exposes_every_account_field(): void
    {
        $user = $this->detail()->json('user');

        // Identité + vie du compte.
        $this->assertSame($this->commercial->id, $user['id']);
        $this->assertSame('jane.doe@example.test', $user['email']);
        $this->assertSame('jdoe', $user['username']);
        $this->assertSame('COMERCIAL', $user['role']);
        $this->assertSame('ACTIVE', $user['status']);
        $this->assertNotEmpty($user['created_at']);
        $this->assertStringStartsWith(
            now()->subDays(3)->format('Y-m-d'),
            (string) $user['email_verified_at']
        );
        // Dernier e-mail d'activation : horodatage utile, jamais le jeton.
        $this->assertStringStartsWith(now()->format('Y-m-d'), (string) $user['verification_sent_at']);
        $this->assertStringContainsString('/storage/avatars/jane.png', $user['avatar_url']);

        // Réglages éditables par la modale.
        $this->assertTrue($user['has_permission']);
        $this->assertFalse($user['gps_bypass']);
        $this->assertNull($user['gps_bypass_expires_at']);
    }

    public function test_detail_exposes_the_full_employee_profile(): void
    {
        $profil = $this->detail()->json('user.profil');

        $this->assertSame('Jane', $profil['prenom']);
        $this->assertSame('Doe', $profil['nom']);
        $this->assertSame('514-555-0111', $profil['telephone']);
        $this->assertSame($this->enterprise->id, $profil['entreprise_id']);
        $this->assertSame('Construction Alpha', $profil['entreprise_name']);
        $this->assertSame('jane-fiche.png', $profil['image_dp']);
        $this->assertStringContainsString('/storage/avatars/jane-fiche.png', $profil['image_dp_url']);
        $this->assertSame('Apte aux chantiers hors semaine.', $profil['info_supp']);

        // Téléphonie : ce que la modale sait écrire + la date de synchro.
        $this->assertSame('device-42', $profil['ringcentral_device_id']);
        $this->assertSame('ext-7', $profil['ringcentral_extension_id']);
        $this->assertSame('+15145550111', $profil['ringcentral_from_number']);
        $this->assertNotEmpty($profil['ringcentral_synced_at']);

        // Entreprise de rattachement vue depuis la fiche.
        $this->assertSame('web', $profil['entreprise']['source']);
        $this->assertStringContainsString('/storage/logos/logo-alpha.png', $profil['entreprise']['logo_url']);
    }

    public function test_detail_exposes_telephony_columns_the_modal_cannot_write(): void
    {
        $employee = $this->detail()->json('employee');

        // `ringcentral_extension_number` et `ringcentral_phone_numbers` sont
        // posés par la synchro RingCentral, jamais par le formulaire : sans eux
        // dans la réponse, la fiche ne peut pas les afficher.
        $this->assertSame('7042', $employee['ringcentral_extension_number']);
        $this->assertSame(['+15145550111', '+14385550111'], $employee['ringcentral_phone_numbers']);
        $this->assertSame('device-42', $employee['ringcentral_device_id']);
        $this->assertSame($this->enterprise->id, $employee['entreprise_id']);
        $this->assertSame('Apte aux chantiers hors semaine.', $employee['additional_info']);

        $entreprise = $this->detail()->json('entreprise');
        $this->assertSame('Construction Alpha', $entreprise['name']);
        $this->assertSame('1234567890RQ0001', $entreprise['tax_number']);
        $this->assertSame('1 rue des Chantiers, Montréal', $entreprise['address']);
        $this->assertSame('web', $entreprise['source']);
        $this->assertStringContainsString('/storage/logos/logo-alpha.png', $entreprise['logo_url']);
    }

    // ==================================================================
    // 2. Non-fuite
    // ==================================================================

    public function test_detail_never_leaks_account_secrets(): void
    {
        $data = $this->detail()->json();

        // `password_hash` est caché par le modèle ; `verification_token` ne l'est
        // **pas** : seule la projection explicite garantit qu'il ne sort jamais.
        // L'horodatage d'envoi, lui, est utile à l'admin (compte en attente).
        $this->assertArrayNotHasKey('verification_token', $data['user']);
        $this->assertArrayHasKey('verification_sent_at', $data['user']);
        $this->assertArrayNotHasKey('password_hash', $data['user']);
        $this->assertArrayNotHasKey('password_hash', $data['employee']);

        $raw = json_encode($data);
        $this->assertStringNotContainsString('token-a-ne-jamais-rendre', $raw);
        $this->assertStringNotContainsString('$2y$', $raw);
    }

    // ==================================================================
    // 3. Dérogation GPS à son état effectif
    // ==================================================================

    public function test_gps_bypass_is_reported_as_the_effective_state(): void
    {
        // Dérogation posée avec une échéance déjà passée : le géorepérage la
        // refusera — la fiche doit dire « inactive » malgré le drapeau `true`.
        $this->commercial->update([
            'gps_bypass' => true,
            'gps_bypass_expires_at' => now()->subHour(),
        ]);

        $user = $this->detail()->json('user');
        $this->assertFalse($user['gps_bypass']);
        $this->assertNotNull($user['gps_bypass_expires_at']);

        // Réactivation par le portail unique : échéance fraîche, état effectif `true`.
        $this->commercial->fresh()->setGpsBypass(true);

        $user = $this->detail()->json('user');
        $this->assertTrue($user['gps_bypass']);
        $this->assertStringStartsWith(
            now()->addHours(User::GPS_BYPASS_TTL_HOURS)->format('Y-m-d\TH:i'),
            (string) $user['gps_bypass_expires_at']
        );
    }

    // ==================================================================
    // 4. Forme partagée avec la page « Employés »
    // ==================================================================

    public function test_detail_profile_is_identical_to_the_list_row_profile(): void
    {
        $fromList = $this->listRow();
        $fromDetail = $this->detail()->json('user');

        // Un écart entre les deux formes = champs de la modale remis à vide
        // selon la page d'où elle est ouverte.
        $this->assertSame($fromList['profil'], $fromDetail['profil']);
        $this->assertSame($fromList['has_permission'], $fromDetail['has_permission']);
        $this->assertSame($fromList['gps_bypass'], $fromDetail['gps_bypass']);
        $this->assertSame($fromList['username'], $fromDetail['username']);
        $this->assertSame($fromList['avatar_url'], $fromDetail['avatar_url']);
    }

    public function test_update_modal_payload_built_from_the_detail_round_trips(): void
    {
        Sanctum::actingAs($this->admin);

        $user = $this->getJson("/api/v1/commercials/{$this->commercial->id}")
            ->assertOk()
            ->json('user');

        // Reproduction exacte de la payload de `useCommercialUpdateModal`,
        // construite **uniquement** depuis la fiche.
        $payload = [
            'email' => $user['email'],
            'username' => $user['username'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'phone' => $user['phone'],
            'enterprise_id' => $user['profil']['entreprise_id'],
            'additional_info' => $user['profil']['info_supp'],
            'ringcentral_extension_id' => $user['profil']['ringcentral_extension_id'],
            'ringcentral_device_id' => 'device-99',
            'ringcentral_from_number' => '+15145550999',
            'has_permission' => false,
            'gps_bypass' => true,
        ];

        $this->putJson("/api/v1/users/{$this->commercial->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.utilisateur.profil.ringcentral_device_id', 'device-99');

        $refreshed = $this->getJson("/api/v1/commercials/{$this->commercial->id}")
            ->assertOk()
            ->json('user');

        // Rien n'a été perdu : les champs non touchés reviennent tels qu'affichés.
        $this->assertSame('jdoe', $refreshed['username']);
        $this->assertSame('jane.doe@example.test', $refreshed['email']);
        $this->assertSame($this->enterprise->id, $refreshed['profil']['entreprise_id']);
        $this->assertSame('Apte aux chantiers hors semaine.', $refreshed['profil']['info_supp']);
        $this->assertSame('ext-7', $refreshed['profil']['ringcentral_extension_id']);
        $this->assertSame('+15145550999', $refreshed['profil']['ringcentral_from_number']);
        $this->assertFalse($refreshed['has_permission']);
        $this->assertTrue($refreshed['gps_bypass']);
    }

    // ==================================================================
    // 5. Compte sans fiche employé
    // ==================================================================

    public function test_detail_survives_a_commercial_without_an_employee_record(): void
    {
        $orphan = User::factory()->create([
            'role' => 'COMERCIAL',
            'status' => 'ACTIVE',
            'first_name' => 'No',
            'last_name' => 'Fiche',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/v1/commercials/{$orphan->id}")->assertOk();

        // `profil` = `null` (et non un objet vide) : la modale retombe alors sur
        // les colonnes `users`, l'entreprise reste absente de la fiche.
        $this->assertNull($response->json('user.profil'));
        $this->assertNull($response->json('entreprise'));
        $this->assertSame('No', $response->json('employee.prenom'));
        $this->assertSame('No', $response->json('user.first_name'));
    }
}
