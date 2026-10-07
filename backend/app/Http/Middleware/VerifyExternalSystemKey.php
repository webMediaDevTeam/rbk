<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clé partagée M2M (scraper / n8n externe) — webhooks publics.
 *
 * Les routes `clients/bulk-upsert`, `clients/bulk-delete`,
 * `clients/convert-to-blacklist`, `clients/convert-to-unavailable` et
 * `clients/create-no-reservations` n'ont ni `auth:sanctum` ni utilisateur
 * : leur barrière est cet en-tête `X-Api-Key`, comparé **en temps constant**
 * à `services.external_system.key` (`EXTERNAL_SYSTEM_API_KEY`, docs/RULES.md
 * §12, spec docs/public_api.md).
 *
 * Échec **fermé** : sans clé configurée côté serveur, tout est refusé —
 * un serveur mal configuré reste fermé, jamais ouvert.
 */
class VerifyExternalSystemKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.external_system.key', '');
        $provided = (string) $request->header('X-Api-Key', '');

        if ($expected === '') {
            return response()->json([
                'success' => false,
                'message' => 'EXTERNAL_SYSTEM_API_KEY non configuré sur le serveur.',
            ], 401);
        }

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'En-tête X-Api-Key manquant ou invalide.',
            ], 401);
        }

        return $next($request);
    }
}
