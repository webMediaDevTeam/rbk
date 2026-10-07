<?php

namespace App\Services;

use App\Models\CallLog;
use App\Models\CallRecording;
use App\Models\Employee;
use Carbon\Carbon;
use Exception;
use Throwable;

/**
 * Passage au réel RingCentral (docs/TODOS.md « Sync Users » +
 * « Sync Call Logs ») : ce service écrit **en base** ce que
 * `RingCentralService` lit chez RingCentral.
 *
 *  Phase 1 — `syncEmployees()` : correspondance employé ↔ poste + numéros
 *  (`GET /account/~/extension`, `/device`, `/phone-number`), stockée dans
 *  `employees.ringcentral_*`.
 *
 *  Phase 2 — `syncCalls()` : journal d'appels (`GET /account/~/extension/
 *  {extensionId}/call-log?view=Detailed`) dé-doublonné dans `call_logs`,
 *  enregistrements dans `call_recordings`.
 *
 *  Appel lancé depuis l'application : `recordOutboundCall()` ouvre la ligne
 *  dès la réponse du call-out (session connue), `syncCalls()` l'enrichit
 *  ensuite au lieu d'en créer une seconde.
 *
 * Toutes les méthodes retournent des tableaux PHP / modèles — aucune
 * réponse HTTP n'est produite ici.
 */
class RingCentralSyncService
{
    public function __construct(private RingCentralService $ringCentral) {}

    // ── Phase 1 : employés ↔ extensions / numéros ───────────────────────

    /**
     * Rapproche chaque fiche employé de son poste RingCentral et stocke la
     * correspondance.
     *
     * Ordre de correspondance (le premier qui matche gagne) :
     *   1. `ringcentral_device_id` déjà choisi sur la fiche → extension de
     *      l'appareil (le choix manuel reste prioritaire) ;
     *   2. e-mail de `users.email` = e-mail de contact du poste ;
     *   3. un numéro de la fiche (`ringcentral_from_number` / `phone`)
     *      assigné à un poste.
     *
     * @return array{
     *   total: int, matched: int, updated: int,
     *   unmatched: list<array{user_id: string, email: string, name: string}>
     * }
     *
     * @throws Exception API injoignable (le contrôleur renvoie 502)
     */
    public function syncEmployees(): array
    {
        // `getAllUsers()` renvoie des objets (SDK `json()`), les deux
        // autres des tableaux (`decode()`) : on aligne sur des tableaux,
        // `matchExtension()` doit pouvoir en renvoyer un.
        $extensions = array_map(fn ($extension) => $this->toArray($extension) ?? [], $this->ringCentral->getAllUsers(250));
        $devices = $this->ringCentral->getDevices(250);
        $phoneNumbers = $this->ringCentral->getPhoneNumbers(500);

        $extById = $this->indexExtensions($extensions);
        $extByEmail = $this->indexExtensionsByEmail($extensions);
        [$devicesById, $devicesByExt] = $this->indexDevices($devices);
        [$numbersByExt, $extByNumber, $primaryByExt] = $this->indexPhoneNumbers($phoneNumbers);

        $report = ['total' => 0, 'matched' => 0, 'updated' => 0, 'unmatched' => []];

        foreach (Employee::query()->with('user')->get() as $employee) {
            $report['total']++;

            $match = $this->matchExtension($employee, $extById, $extByEmail, $devicesById, $extByNumber);

            if ($match === null) {
                $report['unmatched'][] = [
                    'user_id' => (string) $employee->user_id,
                    'email' => (string) ($employee->user?->email ?? ''),
                    'name' => trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                ];

                continue;
            }

            $report['matched']++;

            $changed = $this->applyMapping($employee, $match, $numbersByExt, $primaryByExt, $devicesByExt);

            if ($changed) {
                $report['updated']++;
            }
        }

        return $report;
    }

    /** `id` → extension ; l'extension peut aussi venir d'un appareil seul. */
    private function indexExtensions(iterable $extensions): array
    {
        $index = [];

        foreach ($extensions as $extension) {
            $id = (string) data_get($extension, 'id');

            if ($id !== '') {
                $index[$id] = $extension;
            }
        }

        return $index;
    }

    /** `e-mail` (minuscules) → première extension qui le porte. */
    private function indexExtensionsByEmail(iterable $extensions): array
    {
        $index = [];

        foreach ($extensions as $extension) {
            $email = strtolower(trim((string) data_get($extension, 'contact.email')));

            if ($email !== '' && ! isset($index[$email])) {
                $index[$email] = $extension;
            }
        }

        return $index;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *                                                                 [appareils par id, premier appareil par extension]
     */
    private function indexDevices(iterable $devices): array
    {
        $byId = [];
        $byExt = [];

        foreach ($devices as $device) {
            $id = (string) data_get($device, 'id');

            if ($id !== '') {
                $byId[$id] = $device;
            }

            $extensionId = (string) data_get($device, 'extension.id');

            if ($extensionId !== '' && ! isset($byExt[$extensionId])) {
                $byExt[$extensionId] = $device;
            }
        }

        return [$byId, $byExt];
    }

    /**
     * @return array{0: array<string, list<string>>, 1: array<string, string>, 2: array<string, string>}
     *                                                                                                   [numéros par extension, extension par numéro, numéro primaire
     *                                                                                                   par extension]
     */
    private function indexPhoneNumbers(iterable $phoneNumbers): array
    {
        $byExt = [];
        $extByNumber = [];
        $primaryByExt = [];

        foreach ($phoneNumbers as $number) {
            $phone = (string) data_get($number, 'phoneNumber');

            if ($phone === '') {
                continue;
            }

            $extensionId = (string) data_get($number, 'extension.id');

            if ($extensionId !== '') {
                $byExt[$extensionId][] = $phone;

                if (data_get($number, 'primary') === true) {
                    $primaryByExt[$extensionId] = $phone;
                }
            }

            $key = self::compareNumber($phone);

            if ($key !== '' && ! isset($extByNumber[$key])) {
                $extByNumber[$key] = $extensionId;
            }
        }

        return [$byExt, $extByNumber, $primaryByExt];
    }

    /** @return array<string, mixed>|null  extension appariée, sinon `null` */
    private function matchExtension(
        Employee $employee,
        array $extById,
        array $extByEmail,
        array $devicesById,
        array $extByNumber
    ): ?array {
        // 1. Appareil déjà choisi sur la fiche → extension de l'appareil.
        $deviceId = (string) ($employee->ringcentral_device_id ?? '');

        if ($deviceId !== '' && isset($devicesById[$deviceId])) {
            $device = $devicesById[$deviceId];
            $extensionId = (string) data_get($device, 'extension.id');

            if ($extensionId !== '') {
                return $extById[$extensionId] ?? [
                    'id' => $extensionId,
                    'extensionNumber' => (string) data_get($device, 'extensionNumber', ''),
                ];
            }
        }

        // 2. E-mail de contact du poste = e-mail du compte local.
        $email = strtolower(trim((string) ($employee->user?->email ?? '')));

        if ($email !== '' && isset($extByEmail[$email])) {
            return $extByEmail[$email];
        }

        // 3. Un numéro déjà porté par la fiche est assigné à ce poste.
        foreach ([$employee->ringcentral_from_number, $employee->phone] as $candidate) {
            $key = self::compareNumber((string) $candidate);

            if ($key !== '' && ($extensionId = ($extByNumber[$key] ?? '')) !== '') {
                return $extById[$extensionId] ?? ['id' => $extensionId, 'extensionNumber' => ''];
            }
        }

        return null;
    }

    /** Écrit la correspondance sur la fiche ; `true` si quelque chose a bougé. */
    private function applyMapping(
        Employee $employee,
        array $extension,
        array $numbersByExt,
        array $primaryByExt,
        array $devicesByExt
    ): bool {
        $extensionId = (string) data_get($extension, 'id');
        $extensionNumber = (string) data_get($extension, 'extensionNumber', '');
        $numbers = array_values(array_unique($numbersByExt[$extensionId] ?? []));

        $employee->ringcentral_extension_id = $extensionId === '' ? null : $extensionId;
        $employee->ringcentral_extension_number = $extensionNumber === '' ? null : $extensionNumber;
        $employee->ringcentral_phone_numbers = $numbers === [] ? null : $numbers;
        $employee->ringcentral_synced_at = now();

        // Repli sur la source d'appel quand la fiche est vide : la sélection
        // « Appareil / numéro source » des modales est préremplie par la
        // synchro au lieu de rester à « aucun numéro ».
        if (($employee->ringcentral_device_id ?? '') === '' && isset($devicesByExt[$extensionId])) {
            $employee->ringcentral_device_id = (string) data_get($devicesByExt[$extensionId], 'id') ?: null;
        }

        if (($employee->ringcentral_from_number ?? '') === '') {
            $employee->ringcentral_from_number = $primaryByExt[$extensionId] ?? ($numbers[0] ?? null);
        }

        $changed = $employee->isDirty();

        $employee->save();

        return $changed;
    }

    // ── Phase 2 : journal d'appels + enregistrements ────────────────────

    /**
     * Poste RingCentral de l'employé — sans appel API si la fiche en porte
     * déjà un (`syncEmployees()`), sinon appareil puis e-mail.
     *
     * @return array{string, ?string, string}|null [extension id, numéro, résolu via]
     */
    public function resolveExtension(Employee $employee): ?array
    {
        $stored = (string) ($employee->ringcentral_extension_id ?? '');

        if ($stored !== '') {
            return [$stored, $employee->ringcentral_extension_number, 'stored'];
        }

        $deviceId = (string) ($employee->ringcentral_device_id ?? '');

        if ($deviceId !== '') {
            foreach ($this->ringCentral->getDevices(250) as $device) {
                if ((string) data_get($device, 'id') !== $deviceId) {
                    continue;
                }

                $extensionId = (string) data_get($device, 'extension.id');

                if ($extensionId !== '') {
                    $number = (string) data_get($device, 'extensionNumber', '');

                    return [$extensionId, $number === '' ? null : $number, 'device'];
                }
            }
        }

        $email = strtolower(trim((string) ($employee->user?->email ?? '')));

        if ($email !== '') {
            foreach ($this->ringCentral->getAllUsers(250) as $extension) {
                if (strtolower(trim((string) data_get($extension, 'contact.email'))) !== $email) {
                    continue;
                }

                $extensionId = (string) data_get($extension, 'id');

                if ($extensionId !== '') {
                    $number = (string) data_get($extension, 'extensionNumber', '');

                    return [$extensionId, $number === '' ? null : $number, 'email'];
                }
            }
        }

        return null;
    }

    /**
     * Récupère le journal RingCentral du poste et l'écrit dans `call_logs`
     * (+ `call_recordings`), en dé-doublonnant.
     *
     * @return array{
     *   extension_id: string, extension_number: ?string, resolved_by: string,
     *   fetched: int, created: int, updated: int
     * }|null  `null` si aucun poste n'est rattaché (le contrôleur renvoie 422)
     *
     * @throws Throwable API injoignable (502 côté contrôleur)
     */
    public function syncCalls(Employee $employee, array $filters = []): ?array
    {
        $resolved = $this->resolveExtension($employee);

        if ($resolved === null) {
            return null;
        }

        [$extensionId, $extensionNumber, $resolvedBy] = $resolved;

        // Mémorise le poste sur la fiche : la synchro suivante partira de
        // `ringcentral_extension_id` **sans** rappeler `/device`
        // (quota — voir docs/TODOS.md « 429 CMN-301 »).
        if (($employee->ringcentral_extension_id ?? '') === '' && $extensionId !== '') {
            $employee->ringcentral_extension_id = $extensionId;
            $employee->ringcentral_extension_number = $extensionNumber;
            $employee->save();
        }

        $records = $this->ringCentral->getCallHistoryByUser($extensionId, array_merge([
            'view' => 'Detailed', // `recording.id` + `contentUri` viennent avec
            'perPage' => 100,
        ], $filters));

        $created = 0;
        $updated = 0;

        foreach ($records as $record) {
            $this->upsertCall($record, $employee, $extensionId) ? $created++ : $updated++;
        }

        return [
            'extension_id' => $extensionId,
            'extension_number' => $extensionNumber,
            'resolved_by' => $resolvedBy,
            'fetched' => count($records),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Insère ou complète une ligne du journal ; `true` si la ligne est
     * nouvelle.
     *
     * Clés de rapprochement, dans l'ordre :
     *   1. `id` du call log RingCentral (unique) ;
     *   2. `sessionId` **et** le même employé — c'est ce qui relie un appel
     *      ouvert « à chaud » par `recordOutboundCall()` à son call log.
     */
    public function upsertCall(mixed $record, Employee $employee, string $extensionId): bool
    {
        $callId = (string) data_get($record, 'id', '');
        $sessionId = (string) data_get($record, 'sessionId', '');

        $call = $callId !== '' ? CallLog::query()->where('ringcentral_call_id', $callId)->first() : null;

        if ($call === null && $sessionId !== '') {
            $call = CallLog::query()
                ->where('ringcentral_session_id', $sessionId)
                ->where('employee_id', $employee->id)
                ->first();
        }

        $isNew = $call === null;

        if ($isNew) {
            $call = new CallLog(['employee_id' => $employee->id]);
        }

        $call->ringcentral_extension_id = $extensionId;

        if ($callId !== '') {
            $call->ringcentral_call_id = $callId;
        }

        if ($sessionId !== '') {
            $call->ringcentral_session_id = $sessionId;
        }

        $call->direction = $this->stringOrNull(data_get($record, 'direction')) ?? $call->direction;
        $call->type = $this->stringOrNull(data_get($record, 'type')) ?? $call->type;
        $call->from_number = $this->stringOrNull(data_get($record, 'from.phoneNumber')) ?? $call->from_number;
        $call->from_name = $this->stringOrNull(data_get($record, 'from.name')) ?? $call->from_name;
        $call->to_number = $this->stringOrNull(data_get($record, 'to.phoneNumber')) ?? $call->to_number;
        $call->to_name = $this->stringOrNull(data_get($record, 'to.name')) ?? $call->to_name;
        $call->started_at = $this->dateTime(data_get($record, 'startTime')) ?? $call->started_at ?? now();
        $call->ended_at = $this->dateTime(data_get($record, 'endTime')) ?? $call->ended_at;
        $call->duration = $this->seconds(data_get($record, 'duration')) ?? $call->duration;
        $call->result = $this->stringOrNull(data_get($record, 'result'))
            ?? $this->stringOrNull(data_get($record, 'callResult'))
            ?? $this->stringOrNull(data_get($record, 'terminationReason'))
            ?? $call->result;
        $call->raw = $this->toArray($record) ?? $call->raw;
        $call->synced_at = now();

        $call->save();

        foreach ($this->recordingsOf($record) as $recording) {
            $this->upsertRecording($call, $recording);
        }

        return $isNew;
    }

    /** Métadonnées d'enregistrement d'un appel → `call_recordings`. */
    private function upsertRecording(CallLog $call, mixed $recording): ?CallRecording
    {
        $recordingId = $this->stringOrNull(data_get($recording, 'id'));

        if ($recordingId === null) {
            return null;
        }

        return CallRecording::query()->updateOrCreate(
            ['ringcentral_recording_id' => $recordingId],
            [
                'call_log_id' => $call->id,
                'type' => $this->stringOrNull(data_get($recording, 'type')),
                'duration' => $this->seconds(data_get($recording, 'duration')),
                'file_name' => $this->stringOrNull(data_get($recording, 'fileName')),
                'content_uri' => $this->stringOrNull(data_get($recording, 'contentUri')),
                'synced_at' => now(),
            ]
        );
    }

    /**
     * Un appel sortant lancé depuis l'application : la ligne est ouverte
     * immédiatement (la synchro n'a pas encore l'id du call log, seulement
     * la session) pour que l'appel apparaisse dans l'onglet sans attendre.
     *
     * Un échec d'écriture n'a jamais le droit de faire échouer l'appel.
     */
    public function recordOutboundCall(
        Employee $employee,
        string $sessionId,
        ?string $partyId,
        string $from,
        string $to,
        array $session = []
    ): ?CallLog {
        try {
            $call = CallLog::query()->where('ringcentral_session_id', $sessionId)->first()
                ?? new CallLog(['employee_id' => $employee->id]);

            $call->employee_id = $employee->id;
            $call->ringcentral_session_id = $sessionId;
            $call->ringcentral_party_id = $partyId ?? $call->ringcentral_party_id;
            $call->ringcentral_extension_id = $employee->ringcentral_extension_id;
            $call->direction = 'Outbound';
            $call->type = 'Voice';
            $call->from_number = $from;
            $call->to_number = $to;
            $call->started_at = $call->started_at ?? now();
            $call->result = $this->stringOrNull(data_get($session, 'status.code')) ?? $call->result ?? 'Setup';
            $call->raw = $session === [] ? $call->raw : $session;
            $call->synced_at = now();
            $call->save();

            return $call;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Enregistrement démarré « à chaud » pendant l'appel : la réponse n'est
     * conservée que si elle porte **un id de ressource d'enregistrement**
     * (`uri` / `contentUri`), sinon la synchro rangera le vrai id plus tard.
     *
     * Un échec ne casse jamais l'appel.
     */
    public function attachRecording(?CallLog $call, array $response): ?CallRecording
    {
        if ($call === null) {
            return null;
        }

        $candidates = [
            data_get($response, 'recording'),
            data_get($response, 'recordings.0'),
            $response,
        ];

        foreach ($candidates as $candidate) {
            $recordingId = $this->stringOrNull(data_get($candidate, 'id'));
            $isResource = $this->stringOrNull(data_get($candidate, 'contentUri')) !== null
                || $this->stringOrNull(data_get($candidate, 'uri')) !== null;

            if ($recordingId === null || ! $isResource) {
                continue;
            }

            return $this->upsertRecording($call, $candidate);
        }

        return null;
    }

    /** `recording` peut être un objet ou une liste d'objets. */
    private function recordingsOf(mixed $record): array
    {
        $recording = data_get($record, 'recording') ?? data_get($record, 'recordings');

        if (! is_array($recording) || $recording === []) {
            return [];
        }

        // Objet unique (`['id' => …]`) → liste ; liste déjà → telle quelle.
        return array_is_list($recording) ? $recording : [$recording];
    }

    // ── Utilitaires ─────────────────────────────────────────────────────

    /** `2015-06-25T14:57:30.000Z` / `PT1M30S` / `60` → secondes. */
    public function seconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', trim((string) $value), $m) === 1) {
            return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));
        }

        return null;
    }

    /** Date ISO RingCentral → Carbon ; `null` si illisible. */
    private function dateTime(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text);
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function toArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $decoded = json_decode(json_encode($value), true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /** Comparaison de numéros : chiffres seuls (`+1 (514) 559-4545` = `15145594545`). */
    public static function compareNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($phone, '00')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return $digits;
    }
}
