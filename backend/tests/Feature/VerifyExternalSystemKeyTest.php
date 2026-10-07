<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Clé partagée M2M des webhooks publics — middleware
 * `VerifyExternalSystemKey` (en-tête `X-Api-Key`, docs/RULES.md §12).
 *
 * Surface protégée (routes/api/shared.php) :
 *
 *   POST    clients/bulk-upsert
 *   POST    clients/bulk-delete
 *   DELETE  clients/bulk-delete
 *   POST    clients/convert-to-blacklist
 *   POST    clients/convert-to-unavailable
 *   POST    clients/create-no-reservations
 *
 * Échec fermé : sans `EXTERNAL_SYSTEM_API_KEY` configurée côté serveur,
 * **toute** requête est refusée — un serveur mal configuré reste fermé.
 */
class VerifyExternalSystemKeyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function protectedRoutes(): array
    {
        return [
            'bulk-upsert' => ['postJson', '/api/v1/clients/bulk-upsert'],
            'bulk-delete POST' => ['postJson', '/api/v1/clients/bulk-delete'],
            'bulk-delete DELETE' => ['deleteJson', '/api/v1/clients/bulk-delete'],
            'convert-to-blacklist' => ['postJson', '/api/v1/clients/convert-to-blacklist'],
            'convert-to-unavailable' => ['postJson', '/api/v1/clients/convert-to-unavailable'],
            'create-no-reservations' => ['postJson', '/api/v1/clients/create-no-reservations'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_endpoint_rejects_request_without_api_key(string $method, string $uri): void
    {
        $this->{$method}($uri, ['clients' => []])
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_wrong_api_key_is_rejected(): void
    {
        $this->withHeaders(['X-Api-Key' => 'mauvaise-cle'])
            ->postJson('/api/v1/clients/bulk-upsert', ['clients' => []])
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'En-tête X-Api-Key manquant ou invalide.',
            ]);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_empty_api_key_header_is_rejected(): void
    {
        $this->withHeader('X-Api-Key', '')
            ->postJson('/api/v1/clients/bulk-upsert', ['clients' => []])
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_server_without_configured_key_refuses_everyone(): void
    {
        // Échec fermé : la clé attendue est vide côté serveur → 401, même
        // avec un en-tête présent (message explicite pour le diagnostic).
        config(['services.external_system.key' => '']);

        $this->withHeaders(['X-Api-Key' => 'n-importe-quelle-cle'])
            ->postJson('/api/v1/clients/bulk-upsert', ['clients' => []])
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'EXTERNAL_SYSTEM_API_KEY non configuré sur le serveur.',
            ]);
    }

    public function test_valid_api_key_passes_and_writes_are_executed(): void
    {
        $this->withHeaders(['X-Api-Key' => (string) config('services.external_system.key')])
            ->postJson('/api/v1/clients/bulk-upsert', ['clients' => [
                [
                    'Licence' => 'L-9001',
                    "Nom de l'intervenant / Entreprise" => 'ACME Construction',
                    'Téléphone' => '418-555-0001',
                ],
            ]])
            ->assertOk()
            ->assertJsonPath('success', true);

        // La requête est bien passée jusqu'au contrôleur : le lot est écrit.
        $this->assertSame(1, Client::count());
    }

    public function test_authenticated_application_routes_do_not_require_the_m2m_key(): void
    {
        // `auth/me` est une route d'application : elle répond 401 « non
        // authentifiée » de Laravel, SANS rien qui évoque X-Api-Key —
        // preuve que le middleware ne s'applique qu'aux webhooks M2M.
        $response = $this->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->assertStringNotContainsString('X-Api-Key', $response->getContent());
    }
}
