<?php

namespace App\Services;

use Exception;
use RingCentral\SDK\SDK;

class RingCentralService
{
    protected ?SDK $sdk = null;

    protected $platform = null;

    public function __construct()
    {
        $clientId = config('services.ringcentral.client_id');
        $clientSecret = config('services.ringcentral.client_secret');
        $serverUrl = config('services.ringcentral.server_url', 'https://platform.ringcentral.com');

        if (! empty($clientId) && ! empty($clientSecret)) {
            $this->sdk = new SDK($clientId, $clientSecret, $serverUrl);
            $this->platform = $this->sdk->platform();
        }
    }

    /**
     * Authenticate using JWT
     *
     * @throws Exception
     */
    protected function authenticate(): void
    {
        if (! $this->platform) {
            throw new Exception('RingCentral non configuré (RINGCENTRAL_CLIENT_ID ou CLIENT_SECRET manquant).');
        }

        $jwt = config('services.ringcentral.jwt');
        if (empty($jwt)) {
            throw new Exception('RingCentral non authentifié (RINGCENTRAL_JWT manquant).');
        }

        if (! $this->platform->loggedIn()) {
            $this->platform->login([
                'jwt' => $jwt,
            ]);
        }
    }

    /**
     * Get all users / extensions
     *
     * @throws Exception
     */
    public function getAllUsers(int $perPage = 100): array
    {
        $this->authenticate();

        $response = $this->platform->get('/account/~/extension', [
            'type' => 'User',
            'status' => 'Enabled',
            'perPage' => $perPage,
        ]);

        return $response->json()->records ?? [];
    }

    /**
     * Get call history for a specific user (extension)
     *
     * @throws Exception
     */
    public function getCallHistoryByUser(string $extensionId = '~', array $filters = []): array
    {
        $this->authenticate();

        $params = array_merge([
            'view' => 'Simple',
            'perPage' => 100,
        ], $filters);

        $response = $this->platform->get("/account/~/extension/{$extensionId}/call-log", $params);

        return $response->json()->records ?? [];
    }

    /**
     * Get call history filtered by a target phone number ("To" / Callee)
     * Note: Phone numbers should be in E.164 format without '+' (e.g., 14155552671)
     *
     * @throws Exception
     */
    public function getCallHistoryToNumber(string $phoneNumber, string $extensionId = '~'): array
    {
        $this->authenticate();

        // Sanitize phone number to remove '+' if present
        $cleanNumber = ltrim($phoneNumber, '+');

        $response = $this->platform->get("/account/~/extension/{$extensionId}/call-log", [
            'phoneNumber' => $cleanNumber,
            'direction' => 'Outbound', // Calls sent 'To' this number
            'view' => 'Simple',
            'perPage' => 100,
        ]);

        return $response->json()->records ?? [];
    }

    // ── Contrôle d'appel (phase de test Super Admin) ──────────────────────
    // Pass-through vers RingCentral : aucune écriture en base pour
    // l'instant (docs/TODOS.md « Phase 6bis »). Toutes ces méthodes
    // retournent des **tableaux PHP** (JSON décodé) pour être directement
    // ré-encodés dans la réponse de l'API.

    /**
     * Compte / entreprise RingCentral : `id` = **account_id**, `company` =
     * raison sociale (à stocker lors du passage au réel).
     *
     * GET /restapi/v1.0/account/~
     *
     * @throws Exception
     */
    public function getAccount(): array
    {
        $this->authenticate();

        return $this->decode($this->platform->get('/account/~'));
    }

    /**
     * Appareils de l'account (deskphone / softphone) — source du « from »
     * d'un appel sortant.
     *
     * GET /restapi/v1.0/account/~/device
     *
     * @throws Exception
     */
    public function getDevices(int $perPage = 100): array
    {
        $this->authenticate();

        $data = $this->decode($this->platform->get('/account/~/device', [
            'perPage' => $perPage,
        ]));

        return $data['records'] ?? [];
    }

    /**
     * Numéros de téléphone assignés dans l'account — **libellé de la
     * sélection « Appareil source »** : les softphones ont `phoneLines: []`,
     * le numéro rattaché au poste vit donc ici, rattaché à son extension
     * (`extension.id`, la même que celle d'un appareil).
     *
     * GET /restapi/v1.0/account/~/phone-number
     *
     * @return array  records : `{phoneNumber, extension: {id, extensionNumber}, primary, …}`
     *
     * @throws Exception
     */
    public function getPhoneNumbers(int $perPage = 500): array
    {
        $this->authenticate();

        $data = $this->decode($this->platform->get('/account/~/phone-number', [
            'perPage' => $perPage,
        ]));

        return $data['records'] ?? [];
    }

    /**
     * Extension liée à la session d'authentification (`~`) — **c'est elle
     * qui doit figurer dans `from.extensionId`** d'un call-out :
     *
     *   - `from.extensionId` = autre extension → `400 CMN-101 « value !=
     *     from.extensionId »` ;
     *   - `from.extensionId` = `~`            → `400 CMN-103 « must be
     *     uint64: ~ »` (pas d'alias `~` dans le corps JSON).
     *
     * GET /restapi/v1.0/account/~/extension/~
     *
     * @throws Exception
     */
    public function getMyExtension(): array
    {
        $this->authenticate();

        return $this->decode($this->platform->get('/account/~/extension/~'));
    }

    /**
     * Appel sortant (CallOut) : depuis l'extension de la session (et/ou un
     * appareil, et/ou un caller ID) vers `$to`.
     *
     * POST /restapi/v1.0/account/~/telephony/call-out
     *   { from: {extensionId, phoneNumber?, deviceId?}, to: {phoneNumber} }
     *
     * ⚠️ Deux pièges vérifiés le 2026-10-02 contre l'API réelle :
     *   1. `to` est un **objet** et non un tableau — sinon `400 CMN-103
     *      « JSON can't be parsed: must be object: to »` ;
     *   2. `from.deviceId` renvoie `404 CMN-102 « Resource for parameter
     *      [deviceId] is not found »` sur un compte dont les softphones
     *      n'ont **aucune ligne** (`phoneLines: []`) — d'où la priorité à
     *      `from.extensionId`.
     *
     * @param  string  $to  destination — normalisée en E.164 avec « + »
     * @param  ?string  $fromPhoneNumber  caller ID (doit appartenir à l'extension)
     * @param  ?string  $deviceId  appareil — **seul recours** si aucune extension
     * @param  ?string  $extensionId  extension source (résolue sinon)
     * @return array  réponse brute : `session` (`id` + `parties`)
     *
     * @throws Exception  source illisible, ou réponse vide
     */
    public function makeCallOut(
        string $to,
        ?string $fromPhoneNumber = null,
        ?string $deviceId = null,
        ?string $extensionId = null
    ): array {
        $this->authenticate();

        $extensionId = ($extensionId !== null && $extensionId !== '')
            ? (string) $extensionId
            : (string) ($this->getMyExtension()['id'] ?? '');

        $from = [];

        if ($extensionId !== '') {
            $from['extensionId'] = $extensionId;

            if ($fromPhoneNumber) {
                $from['phoneNumber'] = self::e164($fromPhoneNumber);
            }
        } elseif ($deviceId) {
            // Aucune extension résoluable : on tente l'appareil seul.
            $from['deviceId'] = $deviceId;
        }

        if ($from === []) {
            throw new Exception("Appel impossible : impossible de résoudre l'extension source (`from.extensionId`).");
        }

        $session = $this->decode($this->platform->post('/account/~/telephony/call-out', [
            'from' => $from,
            'to' => ['phoneNumber' => self::e164($to)],
        ]));

        if ($session === []) {
            throw new Exception('Réponse vide de RingCentral pour telephony/call-out.');
        }

        return $session;
    }

    /**
     * Statut d'une session d'appel (parties, statuts) — suivi de l'appel
     * en cours.
     *
     * GET /restapi/v1.0/account/~/telephony/sessions/{sessionId}
     *
     * @throws Exception
     */
    public function getCallSession(string $sessionId): array
    {
        $this->authenticate();

        return $this->decode($this->platform->get('/account/~/telephony/sessions/'.$sessionId));
    }

    /**
     * Démarre l'enregistrement d'une partie de la session.
     *
     * POST /restapi/v1.0/account/~/telephony/sessions/{sessionId}/parties/{partyId}/recordings
     *
     * @throws Exception
     */
    public function startRecording(string $sessionId, string $partyId): array
    {
        $this->authenticate();

        // ⚠️ Aucun corps : le SDK n'encode qu'un tableau **non vide**
        // (`parseProperties()` teste `!empty($body)`), Guzzle recevrait donc
        // `[]` et planterait avec `Invalid resource type: array`.
        return $this->decode($this->platform->post(
            "/account/~/telephony/sessions/{$sessionId}/parties/{$partyId}/recordings"
        ));
    }

    /**
     * Contenu audio d'un enregistrement — **proxy** : le `contentUri` renvoyé
     * par RingCentral n'est lisible qu'avec l'en-tête `Authorization`, qu'un
     * `<audio>` du navigateur ne peut pas envoyer.
     *
     * GET /restapi/v1.0/account/~/recording/{recordingId}  →  contentUri
     * GET {contentUri}                                     →  audio/mpeg
     *
     * @return array{content_type: string, body: string}
     *
     * @throws Exception
     */
    public function getRecordingContent(string $recordingId): array
    {
        $this->authenticate();

        $meta = $this->decode($this->platform->get('/account/~/recording/'.$recordingId));
        $contentUri = trim((string) ($meta['contentUri'] ?? ''));

        if ($contentUri === '') {
            throw new Exception("Enregistrement {$recordingId} introuvable (contentUri absent).");
        }

        // URL absolue : le SDK ne préfixe que les chemins (le préfixe serveur
        // n'est ajouté que si le chemin ne commence pas par `http(s)://`),
        // l'en-tête d'authentification reste ajouté.
        $response = $this->platform->get($contentUri)->response();

        return [
            'content_type' => $response->getHeaderLine('Content-Type') ?: 'audio/mpeg',
            'body'         => (string) $response->getBody(),
        ];
    }

    /**
     * Enregistrements déjà produits pour une partie (id, durée, URI…).
     *
     * GET /restapi/v1.0/account/~/telephony/sessions/{sessionId}/parties/{partyId}/recordings
     *
     * @throws Exception
     */
    public function getRecordings(string $sessionId, string $partyId): array
    {
        $this->authenticate();

        $data = $this->decode($this->platform->get(
            "/account/~/telephony/sessions/{$sessionId}/parties/{$partyId}/recordings"
        ));

        return $data['records'] ?? $data;
    }

    /**
     * Termine (raccroche) une session d'appel.
     *
     * DELETE /restapi/v1.0/account/~/telephony/sessions/{sessionId}
     *
     * @return array  réponse brute (`[]` si corps vide — 204)
     *
     * @throws Exception
     */
    public function hangUpSession(string $sessionId): array
    {
        $this->authenticate();

        return $this->decode($this->platform->delete('/account/~/telephony/sessions/'.$sessionId));
    }

    // ── Utilitaires ──────────────────────────────────────────────────────

    /**
     * Normalise un numéro au format E.164 **avec** le « + » : c'est la
     * forme attendue par `telephony/call-out`
     * (`{ "to": { "phoneNumber": "+79817891689" } }`).
     *
     *   5145594545    → +15145594545   (10 chiffres : plan nord-américain)
     *   1 514 559-4545 → +15145594545
     *   0033 1 23…    → +33123…
     *   +33 1 23…     → +33123…
     */
    public static function e164(string $phone): string
    {
        $raw = trim($phone);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 10) {
            $digits = '1'.$digits; // ex. québécois : 5145594545 → 15145594545
        }

        return '+'.$digits;
    }

    /**
     * Identifiant de session d'une réponse RingCentral : `sessionId`,
     * `session_id`, `id`, sinon dernier segment de `uri`.
     *
     * Le call-out enveloppe la session sous `{"session": {...}}` : on
     * déballe avant de lire (sinon `null` alors que l'appel a réussi).
     */
    public static function sessionIdFrom(array $session): ?string
    {
        $session = self::unwrapSession($session);

        foreach (['sessionId', 'session_id', 'id'] as $key) {
            if (! empty($session[$key]) && is_string($session[$key])) {
                return $session[$key];
            }
        }

        return self::lastUriSegment($session['uri'] ?? null);
    }

    /**
     * Première `partyId` d'une session (statut ou réponse de call-out) :
     * c'est elle qui reçoit l'enregistrement.
     */
    public static function partyIdFrom(array $session): ?string
    {
        $session = self::unwrapSession($session);

        if (! empty($session['partyId']) && is_string($session['partyId'])) {
            return $session['partyId'];
        }

        foreach (($session['parties'] ?? []) as $party) {
            if (is_array($party) && ! empty($party['id'])) {
                return (string) $party['id'];
            }
        }

        return null;
    }

    /** `{"session": {...}}` → `{...}` ; sinon la réponse est déjà déballée. */
    public static function unwrapSession(array $session): array
    {
        return is_array($session['session'] ?? null) ? $session['session'] : $session;
    }

    /** Dernier segment d'une URI (`.../telephony/session/{sessionId}`). */
    private static function lastUriSegment($uri): ?string
    {
        if (! is_string($uri) || $uri === '') {
            return null;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        $segment = $path ? basename($path) : '';

        return $segment === '' ? null : $segment;
    }

    /**
     * Décode la réponse HTTP en tableau PHP ; `[]` si le corps est vide
     * (204 No Content, typique d'un DELETE).
     */
    private function decode($response): array
    {
        $text = trim((string) $response->text());

        if ($text === '') {
            return [];
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : [];
    }
}
