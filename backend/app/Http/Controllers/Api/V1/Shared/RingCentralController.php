<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\RingCentralService;
use App\Services\RingCentralSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contrôle d'appel RingCentral — **phase de test Super Admin**
 * (page `/call-logs-test`, docs/TODOS.md « Phase 6bis »).
 *
 *   - `RingCentralService`      : lectures / écritures **chez** RingCentral ;
 *   - `RingCentralSyncService`  : persistance locale (employés ↔ postes,
 *     `call_logs` + `call_recordings`).
 *
 * L'onglet « Appels » se lit **en base** ; la synchro est un appel explicite
 * (`…/logs/sync`) pour ne jamais déclencher de rafale d'API (429 CMN-301).
 *
 * Endpoints :
 *
 *   GET    /call-logs/account                               (SUPER_ADMIN)
 *   GET    /call-logs/devices                               (ADMIN, SUPER_ADMIN)
 *   POST   /call-logs/sync/employees                        (ADMIN,
 *          SUPER_ADMIN — correspondance employés ↔ postes/numéros)
 *   GET    /call-logs/employees/{id}/logs                   (ADMIN,
 *          SUPER_ADMIN — journal **stocké** de l'employé)
 *   POST   /call-logs/employees/{id}/logs/sync              (ADMIN,
 *          SUPER_ADMIN — récupère le journal RingCentral puis le stocke)
 *   GET    /call-logs/recordings/{recordingId}/content      (ADMIN,
 *          SUPER_ADMIN — proxy audio d'un enregistrement)
 *   POST   /call-logs/my-call                               (COMERCIAL —
 *          appel sortant de l'employé, `from` résolu côté API, journal
 *          ouvert immédiatement + enregistrement automatique)
 *   POST   /call-logs/call                                  (call-out)
 *   GET    /call-logs/calls/{sessionId}                     (statut)
 *   POST   /call-logs/calls/{sessionId}/parties/{partyId}/record
 *          (COMERCIAL, ADMIN, SUPER_ADMIN — démarrage d'enregistrement)
 *   GET    /call-logs/calls/{sessionId}/parties/{partyId}/recordings
 *   DELETE /call-logs/calls/{sessionId}                     (raccroché)
 */
class RingCentralController extends Controller
{
    public function __construct(
        private RingCentralService $ringCentral,
        private RingCentralSyncService $ringCentralSync
    ) {}

    /**
     * 1. Compte / entreprise : `account_id`, société, plan, état.
     */
    public function account(): JsonResponse
    {
        return $this->pass(fn () => $this->ringCentral->getAccount());
    }

    /**
     * 2. Appareils disponibles (source d'un appel sortant), enrichis des
     * **numéros de téléphone rattachés** à leur extension : les softphones
     * renvoient `phoneLines: []`, c'est donc `/account/~/phone-number` qui
     * fournit le libellé affiché dans la sélection (numéro, pas nom).
     *
     * Best-effort : si l'API des numéros est injoignable, les appareils
     * sont renvoyés tels quels (la sélection retombe sur le nom).
     */
    public function devices(Request $request): JsonResponse
    {
        $perPage = max(1, min(250, (int) $request->query('per_page', 100)));

        return $this->pass(fn () => $this->withPhoneNumbers($this->ringCentral->getDevices($perPage)));
    }

    /**
     * Ajoute à chaque appareil `phoneNumbers` (`list<string>`, numéros de
     * son extension + ses propres `phoneLines`) et `phoneNumber` (le
     * premier) — sans jamais échouer l'appareillage pour autant.
     *
     * @param  array  $devices  records RingCentral (tableaux **ou** objets)
     * @return array
     */
    private function withPhoneNumbers(array $devices): array
    {
        try {
            $byExtension = [];

            foreach ($this->ringCentral->getPhoneNumbers(500) as $number) {
                $extensionId = (string) (data_get($number, 'extension.id') ?? '');
                $phoneNumber = (string) (data_get($number, 'phoneNumber') ?? '');

                if ($extensionId !== '' && $phoneNumber !== '') {
                    $byExtension[$extensionId][] = $phoneNumber;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Numéros RingCentral injoignables (libellé des appareils réduit) : '.$e->getMessage());

            return $devices;
        }

        foreach ($devices as $key => $device) {
            $extensionId = (string) (data_get($device, 'extension.id') ?? '');

            $numbers = array_values(array_unique(array_merge(
                array_filter(array_map(
                    fn ($line) => (string) (data_get($line, 'phoneNumber') ?? ''),
                    (array) (data_get($device, 'phoneLines') ?? [])
                )),
                $byExtension[$extensionId] ?? [],
            )));

            if (is_array($device)) {
                $device['phoneNumbers'] = $numbers;
                $device['phoneNumber'] = $numbers[0] ?? null;
            } else { // objet stdClass (records mockés / SDK)
                $device->phoneNumbers = $numbers;
                $device->phoneNumber = $numbers[0] ?? null;
            }

            $devices[$key] = $device;
        }

        return $devices;
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
            $response = $this->ringCentral->startRecording($sessionId, $partyId);

            // Même traitement que lors de l'appel : si RingCentral renvoie
            // un id d'enregistrement exploitable, il est rangé tout de
            // suite (la carte peut alors lire l'audio sans synchro).
            $this->attachRecordingToSession($sessionId, $response);

            return [
                'session_id' => $sessionId,
                'party_id' => $partyId,
                'raw' => $response,
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

    /**
     * 8. Appel sortant d'un **employé** (COMERCIAL) — bouton « Appeler » des
     * pages Mes listes / Rappels / BV.
     *
     * La source n'est **jamais** envoyée par le client : `from` est résolu
     * côté API dans la fiche de l'utilisateur connecté
     * (`employees.ringcentral_from_number` + `ringcentral_device_id`),
     * impossible donc d'emprunter le numéro d'un collègue. Seule la
     * destination (`to`) est reçue du navigateur.
     *
     * `record` (booléen, **`true` par défaut**) démarre l'enregistrement de
     * la session : l'essai est fait dès la réponse, mais la partie est
     * souvent encore en « Setup » → `recorded: false` et le navigateur
     * retente (`POST …/record`, ouvert au COMERCIAL) jusqu'à connexion.
     *
     * POST /call-logs/my-call   { to, record?: bool }
     *
     * @throws ValidationException  aucun numéro source configuré sur la fiche
     */
    public function callAsEmployee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'min:3', 'max:40'],
            'record' => ['sometimes', 'boolean'],
        ]);

        $record = ! array_key_exists('record', $validated) || $validated['record'] === null
            ? true
            : (bool) $validated['record'];

        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        $from = (string) ($employee?->ringcentral_from_number ?? '');

        if ($from === '') {
            throw ValidationException::withMessages([
                'to' => "Aucun numéro source configuré : demandez à un administrateur de choisir votre appareil / numéro (fiche employé) avant d'appeler.",
            ]);
        }

        return $this->pass(function () use ($validated, $employee, $from, $record) {
            $session = RingCentralService::unwrapSession($this->ringCentral->makeCallOut(
                $validated['to'],
                $from,
                $employee->ringcentral_device_id,
                null, // extension = celle de la session (la seule acceptée par RingCentral)
            ));

            $sessionId = RingCentralService::sessionIdFrom($session);
            $partyId = RingCentralService::partyIdFrom($session);

            // Journal ouvert **immédiatement** : l'appel apparaît dans
            // l'onglet « Appels » sans attendre la synchro suivante (la
            // ligne n'a que la session — l'id de call log arrive avec
            // `POST …/logs/sync`, qui complète la même ligne).
            $call = $this->ringCentralSync->recordOutboundCall(
                $employee,
                (string) $sessionId,
                $partyId,
                $from,
                $validated['to'],
                $session
            );

            return [
                'session_id' => $sessionId,
                'party_id' => $partyId,
                'device_id' => $employee->ringcentral_device_id,
                'from' => $from,
                'to' => $validated['to'],
                'record' => $record,
                // Démarrage immédiat **best-effort** : `false` dès que la
                // partie n'est pas encore connectée (cas le plus fréquent),
                // le navigateur retente alors côté client.
                'recorded' => $record && $this->startPartyRecording($sessionId, $partyId, $call),
                'call_log_id' => $call?->id,
                'raw' => $session,
            ];
        });
    }

    /**
     * Démarre l'enregistrement d'une partie — **best-effort** : un échec
     * (partie en « Setup », permission manquante, session déjà terminée…)
     * ne doit jamais faire échouer l'appel, il est simplement signalé par
     * `recorded: false` dans la réponse.
     *
     * Quand la réponse porte un vrai id d'enregistrement (`uri` /
     * `contentUri`), il est rangé dans `call_recordings` : la lecture de
     * l'audio n'attend pas la synchro. La persistance d'une ligne
     * annexe n'a jamais le droit de casser l'appel non plus.
     */
    private function startPartyRecording(?string $sessionId, ?string $partyId, ?CallLog $call = null): bool
    {
        if ($sessionId === null || $sessionId === '' || $partyId === null || $partyId === '') {
            return false;
        }

        try {
            $response = $this->ringCentral->startRecording($sessionId, $partyId);
        } catch (Throwable $e) {
            Log::info('Enregistrement non démarré au moment de l\'appel : '.$e->getMessage(), [
                'session_id' => $sessionId,
                'party_id' => $partyId,
            ]);

            return false;
        }

        $this->attachRecordingToSession($call ?? $sessionId, $response);

        return true;
    }

    /**
     * Range l'enregistrement renvoyé par RingCentral, à partir de son
     * modèle (appel du call-out) ou de la seule session (relance du
     * navigateur sur `…/record`). Silencieux : le reste du flux ne dépend
     * jamais du stockage.
     */
    private function attachRecordingToSession(CallLog|string $callOrSessionId, mixed $response): void
    {
        try {
            $call = $callOrSessionId instanceof CallLog
                ? $callOrSessionId
                : CallLog::query()->where('ringcentral_session_id', $callOrSessionId)->first();

            if ($call !== null && is_array($response)) {
                $this->ringCentralSync->attachRecording($call, $response);
            }
        } catch (Throwable $e) {
            Log::info('Enregistrement non stocké : '.$e->getMessage());
        }
    }

    /**
     * 9. Synchronisation **employés ↔ postes/numéros RingCentral** (Phase 1
     * du passage au réel) — établit la correspondance par appareil choisi,
     * e-mail ou numéro, et la stocke dans `employees.ringcentral_*`
     * (extension, poste, tous les numéros, appareil + numéro source par
     * défaut quand la fiche est vide).
     *
     * POST /call-logs/sync/employees
     *
     * @return JsonResponse  `{total, matched, updated, unmatched[]}`
     */
    public function syncEmployees(): JsonResponse
    {
        try {
            $report = $this->ringCentralSync->syncEmployees();
        } catch (Throwable $e) {
            return $this->failed($e); // 502 : API injoignable
        }

        return response()->json([
            'success' => true,
            'data'    => $report,
        ]);
    }

    /**
     * 10. Journal **stocké** d'un employé — onglet « Appels » de la fiche
     * `/comercialDetail/:id` (ADMIN / SUPER_ADMIN).
     *
     * Lecture locale (`call_logs` + `call_recordings`) : **aucun appel
     * RingCentral**, donc ni latence ni quota `429 CMN-301` à l'ouverture.
     * L'enrichissement passe par `POST …/logs/sync`.
     */
    public function employeeLogs(string $userId): JsonResponse
    {
        $employee = $this->employeeOrFail($userId);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        return response()->json([
            'success' => true,
            'data'    => $this->logsPayload($employee),
        ]);
    }

    /**
     * 11. Récupération du journal RingCentral de l'employé (Phase 2) :
     * poste résolu (fiche → appareil → e-mail), call log `view=Detailed`
     * écrit dans `call_logs` / `call_recordings` **sans doublon**
     * (`ringcentral_call_id` unique, repli sur `session_id` + employé).
     *
     * POST /call-logs/employees/{id}/logs/sync   {dateFrom?, dateTo?, direction?}
     */
    public function syncEmployeeLogs(Request $request, string $userId): JsonResponse
    {
        $employee = $this->employeeOrFail($userId);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        try {
            $sync = $this->ringCentralSync->syncCalls($employee, $request->only([
                'dateFrom', 'dateTo', 'direction',
            ]));
        } catch (Throwable $e) {
            return $this->failed($e); // 502 : API injoignable pendant la synchro
        }

        if ($sync === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Aucune extension RingCentral rattachée à cet employé (appareil « '
                    .($employee->ringcentral_device_id ?? '—').' » introuvable, e-mail « '
                    .($employee->user?->email ?? '—').' » sans correspondance). Choisissez son '
                    .'appareil source dans sa fiche ou lancez la synchronisation des employés.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->logsPayload($employee, $sync),
        ]);
    }

    /** Fiche employé d'un utilisateur ; `422` prêt à renvoyer sinon. */
    private function employeeOrFail(string $userId): Employee|JsonResponse
    {
        $user = User::query()->findOrFail($userId);
        $employee = $user->employee;

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Aucune fiche employé rattachée à cet utilisateur.',
            ], 422);
        }

        return $employee;
    }

    /** Charge le journal stocké (+ enregistrements) d'une fiche employé. */
    private function logsPayload(Employee $employee, ?array $sync = null): array
    {
        $calls = CallLog::query()
            ->with('recordings')
            ->where('employee_id', $employee->id)
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $payload = [
            'source'            => 'db',
            'extension_id'      => $employee->ringcentral_extension_id,
            'extension_number'  => $employee->ringcentral_extension_number,
            'device_id'         => $employee->ringcentral_device_id,
            'from_number'       => $employee->ringcentral_from_number,
            'phone_numbers'     => $employee->ringcentral_phone_numbers,
            'synced_at'         => $employee->ringcentral_synced_at?->toIso8601String(),
            'filtered_by_device' => false,
            'records'           => $calls->map(fn (CallLog $call) => $call->toApiArray())->values()->all(),
        ];

        if ($sync !== null) {
            $payload['sync'] = $sync;
        }

        return $payload;
    }

    /**
     * Proxy audio d'un enregistrement (onglet « Appels ») : le `contentUri`
     * de RingCentral exige l'en-tête `Authorization`, qu'un `<audio>` du
     * navigateur ne peut pas envoyer — le flux est donc relayé ici.
     */
    public function recordingContent(string $recordingId): Response|JsonResponse
    {
        $this->assertIdentifier($recordingId, 'recordingId');

        try {
            $content = $this->ringCentral->getRecordingContent($recordingId);
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        return response($content['body'], 200, [
            'Content-Type'        => $content['content_type'],
            'Content-Length'      => (string) strlen($content['body']),
            'Cache-Control'       => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="recording-'.$recordingId.'.mp3"',
        ]);
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

            // CMN-301 : quota dépassé (rafales d'ouverture d'onglet / échanges
            // `/oauth/token`). Le navigateur réessaie tout seul sous 30 s
            // (`useCallLogs`), on lui dit donc clairement ce qui se passe.
            if ($upstream['status'] === 429) {
                $message = 'Limite de requêtes RingCentral atteinte (429 CMN-301) : patientez une '
                    .'minute, la page réessaie automatiquement. — '.$message;
            }
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
