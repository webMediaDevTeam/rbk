<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook **public** d'import / de suppression de prospects — scraper / n8n
 * (docs/RULES.md §12, spec : docs/public_api.md).
 *
 * `POST clients/bulk-upsert` : **aucune authentification** (aucun middleware
 * `auth:sanctum`), CORS ouvert via `config/cors.php` (`paths` : `api/*`).
 * Deux formes de corps acceptées :
 *
 *   {"clients": [{"Licence": "…", "Nom de l'intervenant / Entreprise": "…"}]}
 *   [{"Licence": "…"}, {"Licence": "…"}]
 *
 * Réponse 200 : `{success, data: {received, processed, created, updated,
 * unchanged, failed, errors[]}}` — `errors[]` porte `index`,
 * `licence_number` et `error` pour chaque entrée rejetée (le reste du lot a
 * bien été écrit). Corps invalide / lot trop long → 422.
 *
 * `POST|DELETE clients/bulk-delete` : suppression en masse, mêmes formes de
 * corps, plus `{"licences": ["L-1", "L-2"]}` et une liste JSON nue de
 * chaînes. **Un client avec des données liées (réservations / notes /
 * rappels) est ignoré** et la boucle passe au client suivant. Réponse
 * `{success, data: {received, processed, deleted, skipped, missing, failed,
 * skipped_items[], missing_items[], errors[]}}`.
 */
class PublicClientController extends Controller
{
    public function bulkUpsert(Request $request): JsonResponse
    {
        $items = $this->items($request, 'importer', ['clients']);

        if ($items instanceof JsonResponse) {
            return $items;
        }

        $result = Client::bulkUpsertFromScraperPayload($items);

        return response()->json([
            // `success = false` uniquement si **aucune** ligne n'a pu être
            // traitée : un import partiel (quelques lignes en erreur) reste
            // une réponse 200 exploitable, `failed` / `errors` détaillent.
            'success' => $result['processed'] > 0,
            'data' => $result,
        ]);
    }

    /**
     * Suppression en masse (`POST` ou `DELETE` `clients/bulk-delete`).
     *
     * Chaque item est traité séparément : client à données liées → `skipped`
     * (aucune suppression, on passe au suivant), client absent → `missing`,
     * client vierge → `deleted`.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $items = $this->items($request, 'supprimer', ['licences', 'clients']);

        if ($items instanceof JsonResponse) {
            return $items;
        }

        $result = Client::bulkDeleteFromScraper($items);

        return response()->json([
            'success' => $result['processed'] > 0,
            'data' => $result,
        ]);
    }

    /**
     * Corps JSON → liste d'items, **ou** la réponse 422 si le format est
     * refusé (d'où le type de retour).
     *
     * @param  list<string>  $envelopes  enveloppes acceptées (`clients`, `licences`)
     * @return array<int, mixed>|JsonResponse
     */
    private function items(Request $request, string $action, array $envelopes): array|JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->invalid('Corps JSON invalide : un tableau de clients est attendu.');
        }

        $items = $decoded;

        foreach ($envelopes as $envelope) {
            if (is_array($decoded[$envelope] ?? null)) {
                $items = $decoded[$envelope];

                break;
            }
        }

        if (! array_is_list($items)) {
            $expected = implode(' / ', array_map(
                fn (string $envelope) => sprintf('{"%s": [...]}', $envelope),
                $envelopes
            ));

            return $this->invalid(sprintf('Format attendu : %s ou une liste JSON.', $expected));
        }

        if ($items === []) {
            return $this->invalid(sprintf('Aucun client à %s (tableau vide).', $action));
        }

        // Garde-fou du endpoint public : un lot reste de taille raisonnable,
        // le scraper appelle plusieurs fois si besoin.
        $maxItems = max(1, (int) config('public_api.max_items'));

        if (count($items) > $maxItems) {
            return $this->invalid(sprintf(
                '%d enregistrements reçus : maximum %d par appel (PUBLIC_API_MAX_ITEMS).',
                count($items),
                $maxItems
            ));
        }

        return $items;
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $message], 422);
    }
}
