<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Exception;
use Illuminate\Support\Facades\Cache;
use RingCentral\SDK\SDK;
use Throwable;

class RingCentralService
{
    /** Jeton d'accès partagé entre requêtes PHP (voir `authenticate()`). */
    protected const AUTH_CACHE = 'ringcentral:auth';

    /** Durée des listes figées (appareils / extensions / numéros). */
    protected const LIST_CACHE_TTL = 60;

    protected ?SDK $sdk = null;

    protected $platform = null;

    /**
     * Identifiants courants : `.env` par défaut, remplacés pour la requête
     * en cours par `configure()` (compte RingCentral d'une entreprise).
     *
     * @var array{client_id: ?string, client_secret: ?string, token: ?string, server_url: string}
     */
    protected array $credentials = [];

    /**
     * Suffixe des clés de cache, dérivé des identifiants courants : deux
     * comptes ne doivent **jamais** partager le jeton d'accès mis en cache
     * (`ringcentral:auth`) ni les listes figées. `''` = compte `.env`.
     */
    protected string $cacheScope = '';

    public function __construct()
    {
        $this->credentials = [
            'client_id' => config('services.ringcentral.client_id'),
            'client_secret' => config('services.ringcentral.client_secret'),
            'token' => config('services.ringcentral.jwt'),
            'server_url' => config('services.ringcentral.server_url', 'https://platform.ringcentral.com'),
        ];

        $this->bootPlatform();
    }

    /**
     * Reconfigure le service **pour la requête en cours** avec les
     * identifiants d'une entreprise (`Enterprise::getRingCentralCredentials()`,
     * déjà repliés sur `.env` champ par champ).
     *
     * `void` (jamais de valeur de retour) : l'instance est propre à la
     * requête — elle n'est ni partagée ni stockée dans le conteneur.
     *
     * @param  array{client_id?: ?string, client_secret?: ?string, token?: ?string, server_url?: string}  $credentials
     */
    public function configure(array $credentials = []): void
    {
        $this->credentials = [
            'client_id' => $credentials['client_id'] ?? config('services.ringcentral.client_id'),
            'client_secret' => $credentials['client_secret'] ?? config('services.ringcentral.client_secret'),
            'token' => $credentials['token'] ?? config('services.ringcentral.jwt'),
            'server_url' => $credentials['server_url']
                ?? config('services.ringcentral.server_url', 'https://platform.ringcentral.com'),
        ];

        $this->cacheScope = ':'.substr(
            hash('sha256', implode('|', [
                $this->credentials['client_id'],
                $this->credentials['client_secret'],
                $this->credentials['token'],
            ])),
            0,
            16,
        );

        $this->bootPlatform();
    }

    /** (Re)construit le SDK / la plateforme à partir des identifiants courants. */
    protected function bootPlatform(): void
    {
        $this->sdk = null;
        $this->platform = null;

        $clientId = $this->credentials['client_id'] ?? null;
        $clientSecret = $this->credentials['client_secret'] ?? null;

        if (! empty($clientId) && ! empty($clientSecret)) {
            $this->sdk = new SDK(
                (string) $clientId,
                (string) $clientSecret,
                (string) ($this->credentials['server_url'] ?? 'https://platform.ringcentral.com'),
            );
            $this->platform = $this->sdk->platform();
        }
    }

    /** Clé de cache isolée par compte (voir `$cacheScope`). */
    protected function scoped(string $key): string
    {
        return $this->cacheScope === '' ? $key : $key.$this->cacheScope;
    }

    /**
     * Authenticate using JWT
     *
     * ⚠️ Le jeton d'accès est **mis en cache entre requêtes PHP** : sans
     * cela, chaque requête ferait un échange `/oauth/token` en plus des
     * appels API — c'est ce qui déclenche le `429 CMN-301 « Request rate
     * exceeded »` rencontré sur l'onglet « Appels ».
     *
     * @throws Exception
     */
    protected function authenticate(): void
    {
        if (! $this->platform) {
            throw new Exception('RingCentral non configuré (identifiants manquants : `ringcentral_client_id` / `ringcentral_client_secret` de l\'entreprise, sinon RINGCENTRAL_CLIENT_ID / RINGCENTRAL_CLIENT_SECRET).');
        }

        // 1. Jeton obtenu par une requête précédente — `expire_time` /
        //    `refresh_token_expire_time` sont absolus, il est donc valable
        //    tel quel. La clé porte l'empreinte des identifiants : un jeton
        //    n'est jamais réutilisé pour un autre compte d'entreprise.
        $cached = Cache::get($this->scoped(self::AUTH_CACHE));
        if (is_array($cached) && $cached !== []) {
            $this->platform->auth()->setData($cached);
        }

        // 2. Toujours valide (ou rafraîchi via le `refresh_token`) →
        //    **aucun appel réseau**.
        if ($this->platform->loggedIn()) {
            $this->storeAuth();

            return;
        }

        $jwt = $this->credentials['token'] ?? null;
        if (empty($jwt)) {
            throw new Exception('RingCentral non authentifié (jeton manquant : `ringcentral_token` de l\'entreprise ou RINGCENTRAL_JWT).');
        }

        $this->platform->login(['jwt' => $jwt]);
        $this->storeAuth();
    }

    /** Re-persiste le jeton courant (TTL = durée résiduelle − 60 s de marge). */
    protected function storeAuth(): void
    {
        try {
            $data = $this->platform->auth()->data();
        } catch (Throwable) {
            return;
        }

        if (! is_array($data) || ($data['access_token'] ?? '') === '') {
            return;
        }

        $expireAt = (int) ($data['expire_time'] ?? 0);
        $ttl = $expireAt > time() ? $expireAt - time() - 60 : 0;

        if ($ttl >= 30) {
            Cache::put($this->scoped(self::AUTH_CACHE), $data, $ttl);
        } else {
            // Déjà expiré : la prochaine requête refera l'échange JWT.
            Cache::forget($this->scoped(self::AUTH_CACHE));
        }
    }

    /**
     * Get all users / extensions
     *
     * @throws Exception
     */
    public function getAllUsers(int $perPage = 100): array
    {
        // Listes figées 60 s : ouvertures répétées de l'onglet « Appels » /
        // des modales employé sans rejouer les mêmes requêtes (CMN-301).
        return Cache::remember(
            $this->scoped("ringcentral:users:{$perPage}"),
            now()->addSeconds(self::LIST_CACHE_TTL),
            function () use ($perPage) {
                $this->authenticate();

                $response = $this->platform->get('/account/~/extension', [
                    'type' => 'User',
                    'status' => 'Enabled',
                    'perPage' => $perPage,
                ]);

                return $response->json()->records ?? [];
            }
        );
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
        // Listes figées 60 s (voir `getAllUsers`) — même garde-fou CMN-301.
        return Cache::remember(
            $this->scoped("ringcentral:devices:{$perPage}"),
            now()->addSeconds(self::LIST_CACHE_TTL),
            function () use ($perPage) {
                $this->authenticate();

                $data = $this->decode($this->platform->get('/account/~/device', [
                    'perPage' => $perPage,
                ]));

                return $data['records'] ?? [];
            }
        );
    }

    /**
     * Numéros de téléphone assignés dans l'account — **libellé de la
     * sélection « Appareil source »** : les softphones ont `phoneLines: []`,
     * le numéro rattaché au poste vit donc ici, rattaché à son extension
     * (`extension.id`, la même que celle d'un appareil).
     *
     * GET /restapi/v1.0/account/~/phone-number
     *
     * @return array records : `{phoneNumber, extension: {id, extensionNumber}, primary, …}`
     *
     * @throws Exception
     */
    public function getPhoneNumbers(int $perPage = 500): array
    {
        // Listes figées 60 s (voir `getAllUsers`) — même garde-fou CMN-301.
        return Cache::remember(
            $this->scoped("ringcentral:phone-numbers:{$perPage}"),
            now()->addSeconds(self::LIST_CACHE_TTL),
            function () use ($perPage) {
                $this->authenticate();

                $data = $this->decode($this->platform->get('/account/~/phone-number', [
                    'perPage' => $perPage,
                ]));

                return $data['records'] ?? [];
            }
        );
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
     * @return array réponse brute : `session` (`id` + `parties`)
     *
     * @throws Exception source illisible, ou réponse vide
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
     * Call log RingCentral d'un appel sortant **récent**, avec ses
     * enregistrements — c'est par ce chemin que l'on retrouve
     * l'enregistrement **automatique** (console admin → Phone System →
     * Call Recording) d'un appel ouvert « à chaud » depuis l'application.
     *
     * ⚠️ On ne peut pas filtrer par session : le `sessionId` du call log est
     * **numérique** (`675097585025`), alors que l'application ne connaît que
     * la session téléphonie (`s-a785e453…`) — deux espaces d'identifiants
     * distincts (et `sessionId` + `withRecording` sont refusés ensemble).
     * On rapproche donc par le **numéro appelé** et l'heure d'appel.
     *
     * GET /restapi/v1.0/account/~/call-log
     *     ?phoneNumber=…&direction=Outbound&dateFrom=…
     *     &view=Detailed&withRecording=true
     *
     * @return array|null `null` si aucun appel ne correspond (ligne non
     *                    encore publiée dans le call log…).
     *
     * @throws Exception autre erreur API (authentification, quota429…)
     */
    public function findCallLogByTarget(string $phoneNumber, CarbonInterface $startedAt): ?array
    {
        $target = preg_replace('/\D+/', '', $phoneNumber);

        if (! is_string($target) || $target === '') {
            return null;
        }

        // Le filtre attend un E.164 sans « + » (11 chiffres ici).
        $filter = strlen($target) < 11 ? '1'.$target : $target;
        $needle = substr($target, -10);
        $startedTs = $startedAt->getTimestamp();

        $this->authenticate();

        try {
            $response = $this->platform->get('/account/~/call-log', [
                'phoneNumber' => $filter,
                'direction' => 'Outbound',
                'dateFrom' => gmdate('Y-m-d\TH:i:s.000\Z', $startedTs - 600),
                'view' => 'Detailed',
                'withRecording' => 'true',
                'perPage' => 10,
            ]);
        } catch (Throwable $e) {
            // Aucun appel pour ces critères : rien à rapatrier.
            if ($this->httpStatus($e) === 404) {
                return null;
            }

            throw $e;
        }

        $records = $this->decode($response)['records'] ?? [];

        if (! is_array($records)) {
            return null;
        }

        // Parmi les lignes au même numéro, on retient l'heure la plus
        // proche de l'appel (fenêtre de ±5 min).
        $best = null;
        $bestDelta = null;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $called = preg_replace('/\D+/', '', (string) data_get($record, 'to.phoneNumber'));
            $caller = preg_replace('/\D+/', '', (string) data_get($record, 'from.phoneNumber'));

            if ((! is_string($called) || ! str_ends_with($called, $needle))
                && (! is_string($caller) || ! str_ends_with($caller, $needle))) {
                continue;
            }

            $started = strtotime((string) data_get($record, 'startTime'));

            if ($started === false) {
                continue;
            }

            $delta = abs($started - $startedTs);

            if ($delta > 300 || ($bestDelta !== null && $delta >= $bestDelta)) {
                continue;
            }

            $best = $record;
            $bestDelta = $delta;
        }

        return $best;
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

        try {
            // ⚠️ Aucun corps : le SDK n'encode qu'un tableau **non vide**
            // (`parseProperties()` teste `!empty($body)`), Guzzle recevrait
            // donc `[]` et planterait avec `Invalid resource type: array`.
            return $this->decode($this->platform->post(
                "/account/~/telephony/sessions/{$sessionId}/parties/{$partyId}/recordings"
            ));
        } catch (Throwable $e) {
            // Variante documentée (docs/ringcentral.md §Task 2) :
            // `POST …/parties/{partyId}/record` avec un identifiant de
            // demande — repli seulement si l'URL n'existe pas (404/405),
            // un 400 « partie pas encore connectée » n'appelle pas ça.
            if (! in_array($this->httpStatus($e), [404, 405], true)) {
                throw $e;
            }

            return $this->decode($this->platform->post(
                "/account/~/telephony/sessions/{$sessionId}/parties/{$partyId}/record",
                ['id' => 'recording-request']
            ));
        }
    }

    /** Statut HTTP de la réponse attachée à l'exception du SDK, sinon `null`. */
    private function httpStatus(Throwable $e): ?int
    {
        if (! method_exists($e, 'apiResponse')) {
            return null;
        }

        $apiResponse = $e->apiResponse();
        $response = $apiResponse ? $apiResponse->response() : null;

        return $response ? $response->getStatusCode() : null;
    }

    /**
     * Contenu audio d'un enregistrement — **proxy** : le `contentUri` renvoyé
     * par RingCentral n'est lisible qu'avec l'en-tête `Authorization`, qu'un
     * `<audio>` du navigateur ne peut pas envoyer.
     *
     * GET /restapi/v1.0/account/~/recording/{recordingId}/content  (1 appel)
     * → repli : métadonnées → `contentUri` (host `media.ringcentral.com`)
     *
     * @return array{content_type: string, body: string}
     *
     * @throws Exception
     */
    public function getRecordingContent(string $recordingId): array
    {
        $this->authenticate();

        try {
            return $this->audio($this->platform->get(
                '/account/~/recording/'.$recordingId.'/content'
            )->response());
        } catch (Throwable $e) {
            if ($this->httpStatus($e) !== 404) {
                throw $e;
            }
        }

        $meta = $this->decode($this->platform->get('/account/~/recording/'.$recordingId));
        $contentUri = trim((string) ($meta['contentUri'] ?? ''));

        if ($contentUri === '') {
            throw new Exception("Enregistrement {$recordingId} introuvable (contentUri absent).");
        }

        // URL absolue : le SDK ne préfixe que les chemins, l'en-tête
        // d'authentification reste ajouté.
        return $this->audio($this->platform->get($contentUri)->response());
    }

    /** Corps binaire + type MIME (`audio/mpeg` / `audio/wav`). */
    private function audio(mixed $response): array
    {
        return [
            'content_type' => $response->getHeaderLine('Content-Type') ?: 'audio/mpeg',
            'body' => (string) $response->getBody(),
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
     * @return array réponse brute (`[]` si corps vide — 204)
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
