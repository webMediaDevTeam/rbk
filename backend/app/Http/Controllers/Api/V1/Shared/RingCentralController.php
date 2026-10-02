<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RingCentralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contrôle d'appel RingCentral — **phase de test Super Admin**
 * (page `/call-logs-test`, docs/TODOS.md « Phase 6bis »).
 *
 * Pass-through pur vers RingCentral : **aucune écriture en base** pour le
 * moment. Le stockage viendra au passage au réel :
 *
 *   - `account_id` + infos société ;
 *   - extensions synchronisées dans des **colonnes `ringcentral_*` de la
 *     table `users`**, pour les seuls utilisateurs `COMERCIAL` ;
 *   - call logs (dé-doublonnés) puis sessions / événements / enregistrements.
 *
 * Endpoints (tous SUPER_ADMIN aujourd'hui) :
 *
 *   GET    /call-logs/account
 *   GET    /call-logs/devices
 *   POST   /call-logs/call                                  (call-out)
 *   GET    /call-logs/calls/{sessionId}                     (statut)
 *   POST   /call-logs/calls/{sessionId}/parties/{partyId}/record
 *   GET    /call-logs/calls/{sessionId}/parties/{partyId}/recordings
 *   DELETE /call-logs/calls/{sessionId}                     (raccroché)
 */
class RingCentralController extends Controller
{
    public function __construct(private RingCentralService $ringCentral) {}

    /**
     * 1. Compte / entreprise : `account_id`, société, plan, état.
     */
    public function account(): JsonResponse
    {
        return $this->pass(fn () => $this->ringCentral->getAccount());
    }

    /**
     * 2. Appareils disponibles (source d'un appel sortant).
     */
    public function devices(Request $request): JsonResponse
    {
        $perPage = max(1, min(250, (int) $request->query('per_page', 100)));

        return $this->pass(fn () => $this->ringCentral->getDevices($perPage));
    }

    /**
     * 3. Appel sortant (`POST /telephony/call-out`) — **retourne le
     * `sessionId`** à passer au suivi / à l'enregistrement / au raccroché.
     *
     * Entrées : `to` (obligatoire) + une source :
     *   - `device_id` (appareil sélectionné) et/ou `from` (numéro source) ;
     *   - ou `user_id` : l'extension RingCentral et son appareil sont
     *     **résolus côté API** (correspondance d'e-mail), puis utilisés.
     */
    public function makeCall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'min:3', 'max:40'],
            'user_id' => ['nullable', 'string', 'max:36', 'exists:users,id'],
            'extension_id' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $source = $this->resolveSource($validated);
        } catch (ValidationException $e) {
            throw $e; // 422 : source illisible pour l'utilisateur
        } catch (Throwable $e) {
            return $this->failed($e); // 502 : API injoignable pendant la résolution
        }

        return $this->pass(function () use ($validated, $source) {
            $session = RingCentralService::unwrapSession($this->ringCentral->makeCallOut(
                $validated['to'],
                $source['from'],
                $source['device_id'],
                $source['extension_id']
            ));

            return [
                'session_id' => RingCentralService::sessionIdFrom($session),
                'party_id' => RingCentralService::partyIdFrom($session),
                'device_id' => $source['device_id'],
                'extension_id' => $source['extension_id'],
                'from' => $source['from'],
                'to' => $validated['to'],
                'raw' => $session,
            ];
        });
    }

    /**
     * 4. Statut d'un appel actif (`sessionId`) + liste des `parties`
     * (le `partyId` sert à l'enregistrement).
     */
    public function callStatus(Request $request, string $sessionId): JsonResponse
    {
        $this->assertIdentifier($sessionId, 'sessionId');

        return $this->pass(function () use ($sessionId) {
            $session = RingCentralService::unwrapSession($this->ringCentral->getCallSession($sessionId));

            return [
                'session_id' => RingCentralService::sessionIdFrom($session) ?? $sessionId,
                'status' => $session['status'] ?? null,
                'parties' => $this->parties($session),
                'raw' => $session,
            ];
        });
    }

    /**
     * 5. Démarre l'enregistrement d'une partie de la session.
     */
    public function record(Request $request, string $sessionId, string $partyId): JsonResponse
    {
        $this->assertIdentifier($sessionId, 'sessionId');
        $this->assertIdentifier($partyId, 'partyId');

        return $this->pass(function () use ($sessionId, $partyId) {
            return [
                'session_id' => $sessionId,
                'party_id' => $partyId,
                'raw' => $this->ringCentral->startRecording($sessionId, $partyId),
            ];
        });
    }

    /**
     * 6. Enregistrements d'une partie.
     */
    public function recordings(Request $request, string $sessionId, string $partyId): JsonResponse
    {
        $this->assertIdentifier($sessionId, 'sessionId');
        $this->assertIdentifier($partyId, 'partyId');

        return $this->pass(function () use ($sessionId, $partyId) {
            $records = $this->ringCentral->getRecordings($sessionId, $partyId);

            return [
                'session_id' => $sessionId,
                'party_id' => $partyId,
                'count' => count($records),
                'recordings' => $records,
            ];
        });
    }

    /**
     * 7. Raccroche / termine la session.
     */
    public function hangUp(Request $request, string $sessionId): JsonResponse
    {
        $this->assertIdentifier($sessionId, 'sessionId');

        return $this->pass(function () use ($sessionId) {
            return [
                'session_id' => $sessionId,
                'raw' => $this->ringCentral->hangUpSession($sessionId),
            ];
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Source de l'appel (`extension_id` / `deviceId` / `from`), en résolvant
     * éventuellement l'extension et l'appareil d'un utilisateur local via
     * l'API RingCentral (aucune colonne `ringcentral_*` en base **pour
     * l'instant**).
     *
     * En dernier recours, on retombe sur **l'extension de la session
     * d'authentification** : c'est la seule que RingCentral accepte comme
     * `from.extensionId` (`400 CMN-101 « value != from.extensionId »` sinon).
     *
     * @param  array{to: string, user_id?: ?string, extension_id?: ?string, device_id?: ?string, from?: ?string}  $validated
     * @return array{device_id: ?string, from: ?string, extension_id: ?string}
     *
     * @throws ValidationException  aucune source résoluble
     */
    private function resolveSource(array $validated): array
    {
        $source = [
            'device_id' => ($validated['device_id'] ?? null) ?: null,
            'from' => ($validated['from'] ?? null) ?: null,
            'extension_id' => ($validated['extension_id'] ?? null) ?: null,
        ];

        if (! empty($validated['user_id'])) {
            $user = User::query()->findOrFail($validated['user_id']);

            // 1. Extension RingCentral dont l'e-mail correspond au compte local.
            $extension = $this->findExtensionByEmail((string) $user->email);

            if ($source['extension_id'] === null && $extension !== null) {
                $source['extension_id'] = (string) ($extension->id ?? $extension->extensionNumber ?? '');
            }

            // 2. Premier appareil rattaché à cette extension (si aucune source).
            if ($source['device_id'] === null && $source['from'] === null && $extension !== null) {
                $source['device_id'] = $this->deviceOfExtension($extension);
            }

            // 3. Toujours rien : l'appel est impossible sans source.
            $this->assertSource($source, (string) $user->email);

            return $source;
        }

        // Extension de la session (`~`) — source par défaut d'un call-out.
        if ($source['extension_id'] === null) {
            $source['extension_id'] = $this->sessionExtensionId();
        }

        $this->assertSource($source);

        return $source;
    }

    /**
     * Identifiant numérique de l'extension de la session d'authentification
     * ; `null` si l'API ne répond pas (→ message « source requise » plutôt
     * qu'un échec silencieux).
     */
    private function sessionExtensionId(): ?string
    {
        try {
            $extension = $this->ringCentral->getMyExtension();
        } catch (Throwable $e) {
            Log::warning('Extension de session RingCentral introuvable : '.$e->getMessage());

            return null;
        }

        $id = (string) ($extension['id'] ?? '');

        return $id === '' ? null : $id;
    }

    /** Première extension `Enabled` dont l'e-mail de contact correspond. */
    private function findExtensionByEmail(string $email): ?object
    {
        if (trim($email) === '') {
            return null;
        }

        foreach ($this->ringCentral->getAllUsers(250) as $extension) {
            $extensionEmail = strtolower((string) ($extension->contact->email ?? ''));

            if ($extensionEmail !== '' && $extensionEmail === strtolower(trim($email))) {
                return $extension;
            }
        }

        return null;
    }

    /** `id` du premier appareil appartenant à l'extension, sinon `null`. */
    private function deviceOfExtension(object $extension): ?string
    {
        $extensionId = (string) ($extension->id ?? '');
        $extensionNumber = (string) ($extension->extensionNumber ?? '');

        foreach ($this->ringCentral->getDevices(250) as $device) {
            $deviceExtensionId = (string) ($device->extension->id ?? '');
            $deviceExtensionNumber = (string) ($device->extensionNumber ?? '');

            $match = ($extensionId !== '' && $deviceExtensionId === $extensionId)
                || ($extensionNumber !== '' && $deviceExtensionNumber === $extensionNumber);

            if ($match && ! empty($device->id)) {
                return (string) $device->id;
            }
        }

        return null;
    }

    /**
     * @param  array{device_id: ?string, from: ?string, extension_id?: ?string}  $source
     *
     * @throws ValidationException
     */
    private function assertSource(array $source, ?string $email = null): void
    {
        if (! empty($source['extension_id']) || ! empty($source['device_id']) || ! empty($source['from'])) {
            return;
        }

        throw ValidationException::withMessages([
            'device_id' => $email === null
                ? "Source d'appel introuvable : l'extension de session n'a pas été résolue. Précisez `extension_id`, `device_id` ou `from`."
                : "Aucune extension RingCentral ne correspond à l'utilisateur « {$email} » : précisez `extension_id`, `device_id` ou `from`.",
        ]);
    }

    /**
     * Identifiant de route (`sessionId`, `partyId`) : bloqué avant qu'il ne
     * soit injecté dans l'URL d'appel RingCentral (pas de `/`, `..`, espace…).
     *
     * @throws ValidationException
     */
    private function assertIdentifier(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z0-9._\-]+$/', $value) === 1) {
            return;
        }

        throw ValidationException::withMessages([
            $field => "Identifiant invalide ({$field}).",
        ]);
    }

    /** Parties d'une session, résumées pour la page de test. */
    private function parties(array $session): array
    {
        $parties = [];

        foreach (($session['parties'] ?? []) as $party) {
            if (! is_array($party)) {
                continue;
            }

            $parties[] = [
                'id' => $party['id'] ?? null,
                'status' => $party['status'] ?? null,
                'direction' => $party['direction'] ?? null,
                'from' => $party['from']['phoneNumber'] ?? ($party['from']['name'] ?? null),
                'to' => $party['to']['phoneNumber'] ?? ($party['to']['name'] ?? null),
            ];
        }

        return $parties;
    }

    /**
     * Exécute `$callback` et renvoie `{success, data}` ; toute exception
     * (SDK non configuré, JWT expiré, API injoignable…) devient un `502`
     * avec `{success: false, error}` — même contrat que `CallLogController`.
     */
    private function pass(callable $callback): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $callback(),
            ]);
        } catch (Throwable $e) {
            return $this->failed($e);
        }
    }

    private function failed(Throwable $e): JsonResponse
    {
        Log::error('Erreur RingCentral : '.$e->getMessage(), ['exception' => $e::class]);

        $upstream = $this->upstream($e);

        $message = 'Erreur de communication avec le service de téléphonie : '.$e->getMessage();

        if ($upstream !== null) {
            $message .= ' — RingCentral '.$upstream['status'].' '.$upstream['reason']
                .($upstream['detail'] !== '' ? ' : '.$upstream['detail'] : '');
        }

        return response()->json([
            'success' => false,
            'error' => $message,
            'upstream' => $upstream,
        ], 502);
    }

    /**
     * Réponse HTTP **brute** de RingCentral attachée à l'exception du SDK
     * (`RingCentral\SDK\Http\ApiException`) : statut, en-têtes utiles et
     * corps complet. Le message du SDK se limite à « 400 Bad Request »
     * quand le corps ne porte pas de champ `message` (ex. `CMN-103`) :
     * sans ce déballage l'UI ne peut pas expliquer l'échec.
     *
     * @return array{status: int, reason: string, request_id: ?string, detail: string, body: mixed}|null
     *         `null` si l'exception ne provient pas d'un échange HTTP
     *         (SDK non configuré, JWT expiré, validation…).
     */
    private function upstream(Throwable $e): ?array
    {
        if (! method_exists($e, 'apiResponse')) {
            return null;
        }

        $apiResponse = $e->apiResponse();
        $response = $apiResponse ? $apiResponse->response() : null;

        if (! $response) {
            return null;
        }

        $raw = trim((string) $response->getBody());

        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $body = $raw; // corps non-JSON (HTML, vide…) : on le renvoie tel quel
        }

        return [
            'status' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'request_id' => $response->getHeaderLine('RCRequestId') ?: null,
            'detail' => $this->upstreamDetail($body),
            'body' => $body,
        ];
    }

    /** Version lisible de `upstream.body` : `errorCode — message` de chaque erreur. */
    private function upstreamDetail(mixed $body): string
    {
        if (! is_array($body)) {
            return is_string($body) ? mb_substr($body, 0, 500) : '';
        }

        $errors = [];

        foreach (($body['errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $text = trim(trim(($error['errorCode'] ?? '').' — '.($error['message'] ?? '')), '— ');

            if ($text !== '') {
                $errors[] = $text;
            }
        }

        if ($errors !== []) {
            return implode(' ; ', $errors);
        }

        return (string) ($body['message'] ?? ($body['error_description'] ?? ($body['description'] ?? '')));
    }
}
