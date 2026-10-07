<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Client\ClientImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

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
 *
 * `POST clients/convert-to-blacklist` : conversion en liste noire, cible
 * **nom** ou **licence** (endpoint public **temporaire**, spec
 * `docs/convert_to_blacklist_api.md`). L'enveloppe choisit le mode :
 * `{"name"|"names"}` → nom, `{"licence"|"licences"}` → licence,
 * `{"clients": [...]}` ou liste JSON nue → **auto** (licence si l'item en
 * porte une, sinon nom). Le nom est comparé à `enterprise_name` **ou** `name`
 * sans tenir compte de la casse ; la licence est recherchée exactement dans
 * `licence_propre_numero` (« Licence (propre) ») puis `licence_number`.
 * Une cible **introuvable est ignorée** (pas une erreur). Réponse
 * `{success, data: {received, processed, matched, zapped, ignored,
 * not_found, already_blacklisted, failed, zapped_items[],
 * ignored_items[], errors[]}}`.
 *
 * `POST clients/convert-to-unavailable` : indisponibilité **par numéro de
 * téléphone** (endpoint public **temporaire**, spec
 * `docs/convert_to_unavailable_api.md`). Formes `{"phone": "…"}`,
 * `{"phones": [...]}` ou liste JSON nue ; le numéro est **détecté quel que
 * soit son format** (`819-418-6550`, `+1-819-418-6550`… via
 * `Client::normalizePhone()`) et toutes les lignes correspondantes passent
 * en `UNAVAILABLE` avec `returned_at = now + 3 mois`. Un numéro
 * **introuvable est ignoré** (pas une erreur). Réponse `{success, data:
 * {received, processed, matched, blocked, ignored, not_found,
 * already_unavailable, blacklisted, failed, blocked_items[],
 * ignored_items[], errors[]}}`.
 *
 * `POST clients/create-no-reservations` : fausses réservations **NON**
 * (endpoint public **temporaire**, spec
 * `docs/create_no_reservations_api.md`). Une ligne `reservations`
 * (`status = NO`) par client visé, attribuée à **un seul employé** (jamais
 * répartie sur tous les commerciaux), **sans aucun effet de bord métier**.
 * Cibles : `{"status": "UNAVAILABLE"}` (tout un périmètre), `{"licence": …}`,
 * `{"licences": [...]}`, `{"client_id": …}`, `{"client_ids": [...]}`,
 * `{"clients": [...]}` ou liste JSON nue (uuid **ou** licence). Réponse
 * `{success, data: {received, processed, matched, created, already, skipped,
 * not_found, failed, comercial_email, truncated, created_items[],
 * ignored_items[], errors[]}}`.
 */
class PublicClientController extends Controller
{
    public function bulkUpsert(Request $request): JsonResponse
    {
        $items = $this->items($request, 'importer', ['clients']);

        if ($items instanceof JsonResponse) {
            return $items;
        }

        $result = ClientImportService::bulkUpsertFromScraperPayload($items);

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

        $result = ClientImportService::bulkDeleteFromScraper($items);

        return response()->json([
            'success' => $result['processed'] > 0,
            'data' => $result,
        ]);
    }

    /**
     * Conversion en liste noire — cible **nom** ou **licence** (`POST
     * clients/convert-to-blacklist`) — endpoint public **temporaire**
     * (spec : docs/convert_to_blacklist_api.md).
     *
     * L'enveloppe du corps choisit le mode :
     *
     *   {"name": "Entreprises Richard Forget & Fils Inc."}   → nom
     *   {"names": ["Nom A", "Nom B"]}                         → nom
     *   {"licence": "1234"}                                  → licence
     *   {"licences": ["1234", "RB-5678"]}                    → licence
     *   {"clients": [{...}, ...]} / ["…", "…"]              → **auto**
     *                                                          (item par item :
     *                                                          licence si l'item
     *                                                          en porte une, sinon
     *                                                          nom)
     *
     * Le nom visé est comparé à `enterprise_name` **ou** `name`, sans tenir
     * compte de la casse ; la licence est recherchée **exactement** dans
     * `licence_propre_numero` (« Licence (propre) ») puis `licence_number`.
     * Une cible peut viser plusieurs lignes : **toutes** passent en liste
     * noire.
     *
     * Une cible **inexistante est ignorée** (pas une erreur) : elle compte
     * dans `ignored` / `not_found` et le lot continue. Réponse 200, rapport
     * consolidé `{success, data: {received, processed, matched, zapped,
     * ignored, not_found, already_blacklisted, failed, zapped_items[],
     * ignored_items[], errors[]}}` — chaque ligne de rapport porte `type`
     * (`name` / `licence`), `key` et, selon le mode, `name` **ou**
     * `licence`. Corps invalide / lot trop long → 422.
     */
    public function convertToBlacklist(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        $mode = ClientImportService::BLACKLIST_MODE_AUTO;

        if (is_array($decoded) && ! array_is_list($decoded)) {
            if (array_key_exists('licence', $decoded) || array_key_exists('licences', $decoded)) {
                $mode = ClientImportService::BLACKLIST_MODE_LICENCE;
            } elseif (array_key_exists('name', $decoded) || array_key_exists('names', $decoded)) {
                $mode = ClientImportService::BLACKLIST_MODE_NAME;
            }
        }

        $items = $mode === ClientImportService::BLACKLIST_MODE_LICENCE
            ? $this->licenceItems($request)
            : $this->nameItems($request);

        if ($items instanceof JsonResponse) {
            return $items;
        }

        $result = match ($mode) {
            ClientImportService::BLACKLIST_MODE_LICENCE => ClientImportService::convertToBlacklistFromLicence($items),
            ClientImportService::BLACKLIST_MODE_NAME => ClientImportService::convertToBlacklistFromName($items),
            default => ClientImportService::convertToBlacklistFromPayload($items),
        };

        return response()->json([
            // `success = false` uniquement si **aucun** item n'a pu être
            // examiné : un lot partiel reste une réponse 200 exploitable.
            'success' => $result['processed'] > 0,
            'data' => $result,
        ]);
    }

    /**
     * Corps JSON → liste de **licences** pour la conversion en liste noire,
     * **ou** la réponse 422 si le format est refusé.
     *
     * @return list<mixed>|JsonResponse
     */
    private function licenceItems(Request $request): array|JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        // Forme simple : {"licence": "…"} — une seule licence, pas une liste.
        if (is_array($decoded) && ! array_is_list($decoded) && array_key_exists('licence', $decoded)) {
            if (! is_scalar($decoded['licence']) || is_bool($decoded['licence'])) {
                return $this->invalid('Le champ « licence » doit être une chaîne ou un nombre.');
            }

            if (trim((string) $decoded['licence']) === '') {
                return $this->invalid('Aucune licence à traiter (champ « licence » vide).');
            }

            return [$decoded['licence']];
        }

        // {"licences": [...]}, {"clients": [...]} ou liste JSON nue.
        return $this->items($request, 'mettre en liste noire', ['licences', 'clients']);
    }

    /**
     * Corps JSON → liste de **noms** pour la conversion en liste noire,
     * **ou** la réponse 422 si le format est refusé.
     *
     * @return list<mixed>|JsonResponse
     */
    private function nameItems(Request $request): array|JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        // Forme simple : {"name": "…"} — un seul nom, pas une liste.
        if (is_array($decoded) && ! array_is_list($decoded) && array_key_exists('name', $decoded)) {
            if (! is_string($decoded['name'])) {
                return $this->invalid('Le champ « name » doit être une chaîne.');
            }

            if (trim($decoded['name']) === '') {
                return $this->invalid('Aucun nom à traiter (champ « name » vide).');
            }

            return [$decoded['name']];
        }

        // {"names": [...]}, {"clients": [...]} ou liste JSON nue.
        return $this->items($request, 'mettre en liste noire', ['names', 'clients']);
    }

    /**
     * Fausses réservations « NON » (`POST clients/create-no-reservations`) —
     * endpoint public **temporaire** (spec :
     * `docs/create_no_reservations_api.md`).
     *
     * Écrit une ligne `reservations` (`status = NO`) par client visé,
     * attribuée à **un seul employé** — pas à tous les commerciaux.
     * Aucun effet de bord métier : ni note `NO`, ni `returned_at`, ni
     * auto-liste-noire (le workflow réel passe par
     * `CallWorkflowService::apply()`).
     *
     * Deux Modes :
     *
     *   {"status": "UNAVAILABLE"}            → tous les clients du statut
     *                                         (+ `{"after": "<uuid>"}` pour
     *                                          dérouler un périmètre plus
     *                                          grand que le plafond)
     *   {"licence": "1100-3571-01"}          → un client
     *   {"licences": ["…", "…"]}             → un lot de clients
     *   {"client_id": "uuid"} / {"client_ids": [...]}
     *   {"clients": [...]} / ["…", "…"]     → lot (uuid ou licence)
     *
     * Un client introuvable, déjà porteur d'une réservation `NO` de cet
     * employé, ou en liste noire est **ignoré** (jamais une erreur) ; le
     * lot est donc ré-exécutable. Réponse 200, rapport consolidé
     * `{success, data: {received, processed, matched, created, already,
     * skipped, not_found, failed, comercial_email, truncated,
     * created_items[], ignored_items[], errors[]}}`.
     */
    public function createNoReservations(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);
        $comercial = (string) config(
            'public_api.no_reservations_comercial_email',
            ClientImportService::NO_RESERVATIONS_COMERCIAL_EMAIL
        );

        // Mode « périmètre » : le script connaît le statut, pas les clients.
        if (is_array($decoded) && ! array_is_list($decoded) && array_key_exists('status', $decoded)) {
            if (array_key_exists('clients', $decoded) || array_key_exists('licences', $decoded)) {
                return $this->invalid(
                    'Corps ambigu : fournissez « status », ou une liste de clients — pas les deux.'
                );
            }

            if (! is_string($decoded['status'])) {
                return $this->invalid('Le champ « status » doit être une chaîne.');
            }

            // Curseur de reprise : identifiant **exclu**, renvoyé par
            // l'appel précédent (`next_after`).
            if (isset($decoded['after']) && ! is_string($decoded['after'])) {
                return $this->invalid('Le champ « after » doit être une chaîne.');
            }

            try {
                $result = ClientImportService::noReservationsFromStatus(
                    $decoded['status'],
                    $comercial,
                    isset($decoded['after']) ? $decoded['after'] : null
                );
            } catch (InvalidArgumentException $e) {
                return $this->invalid($e->getMessage());
            }

            return response()->json([
                'success' => $result['processed'] > 0,
                'data' => $result,
            ]);
        }

        $items = $this->noReservationItems($request);

        if ($items instanceof JsonResponse) {
            return $items;
        }

        try {
            $result = ClientImportService::noReservationsFromItems($items, $comercial);
        } catch (InvalidArgumentException $e) {
            // Employé absent de la base : configuration, pas requête invalide.
            return $this->invalid($e->getMessage());
        }

        return response()->json([
            // `success = false` uniquement si **aucun** client n'a pu être
            // examiné : un lot partiel reste une réponse 200 exploitable.
            'success' => $result['processed'] > 0,
            'data' => $result,
        ]);
    }

    /**
     * Corps JSON → liste de cibles (identifiants / licences), **ou** la
     * réponse 422 si le format est refusé.
     *
     * @return list<mixed>|JsonResponse
     */
    private function noReservationItems(Request $request): array|JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        // Formes simples : une seule cible, pas une liste.
        foreach ([['client_id', 'client_ids'], ['licence', 'licences']] as [$single, $plural]) {
            if (! is_array($decoded) || array_is_list($decoded) || ! array_key_exists($single, $decoded)) {
                continue;
            }

            if (! is_scalar($decoded[$single]) || is_bool($decoded[$single])) {
                return $this->invalid(sprintf(
                    'Le champ « %s » doit être une chaîne ou un nombre.',
                    $single
                ));
            }

            if (trim((string) $decoded[$single]) === '') {
                return $this->invalid(sprintf(
                    'Aucune cible à traiter (champ « %s » vide).',
                    $single
                ));
            }

            return [$decoded[$single]];
        }

        // {"client_ids": [...]}, {"licences": [...]}, {"clients": [...]}
        // ou liste JSON nue.
        return $this->items($request, 'recevoir une fausse réservation', [
            'client_ids',
            'licences',
            'clients',
        ]);
    }

    /**
     * Indisponibilité **par numéro de téléphone** (`POST
     * clients/convert-to-unavailable`) — endpoint public **temporaire**
     * (spec : docs/convert_to_unavailable_api.md).
     *
     * Trois formes de corps acceptées :
     *
     *   {"phone": "819-418-6550"}
     *   {"phones": ["819-418-6550", "+1-418-555-1212"]}
     *   ["819-418-6550", "418-555-1212"]
     *
     * Le numéro est **détecté quel que soit son format** des deux côtés
     * (`Client::normalizePhone()` : `8194186550`, `+1819-418-6550`,
     * `+1-819-418-6550`… désignent tous le même numéro) ; toutes les
     * lignes correspondantes passent en `UNAVAILABLE` avec
     * `returned_at = now + 3 mois` (geste NO, RULES §3).
     *
     * Un numéro **inexistant est ignoré** (pas une erreur) : il compte dans
     * `ignored` / `not_found` et le lot continue. Réponse 200, rapport
     * consolidé `{success, data: {received, processed, matched, blocked,
     * ignored, not_found, already_unavailable, blacklisted, failed,
     * blocked_items[], ignored_items[], errors[]}}` — corps invalide / lot
     * trop long → 422.
     */
    public function convertToUnavailable(Request $request): JsonResponse
    {
        $items = $this->phoneItems($request);

        if ($items instanceof JsonResponse) {
            return $items;
        }

        $result = ClientImportService::bulkUnavailableFromPhone($items);

        return response()->json([
            // `success = false` uniquement si **aucun** numéro n'a pu être
            // examiné : un lot partiel reste une réponse 200 exploitable.
            'success' => $result['processed'] > 0,
            'data' => $result,
        ]);
    }

    /**
     * Corps JSON → liste de **numéros** pour l'indisponibilité en masse,
     * **ou** la réponse 422 si le format est refusé.
     *
     * @return list<mixed>|JsonResponse
     */
    private function phoneItems(Request $request): array|JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        // Forme simple : {"phone": "…"} — un seul numéro, pas une liste.
        if (is_array($decoded) && ! array_is_list($decoded) && array_key_exists('phone', $decoded)) {
            if (! is_string($decoded['phone'])) {
                return $this->invalid('Le champ « phone » doit être une chaîne.');
            }

            if (trim($decoded['phone']) === '') {
                return $this->invalid('Aucun numéro à traiter (champ « phone » vide).');
            }

            return [$decoded['phone']];
        }

        // {"phones": [...]}, {"clients": [...]} ou liste JSON nue.
        return $this->items($request, 'rendre indisponible', ['phones', 'clients']);
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
