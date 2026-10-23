<?php

namespace App\Http\Controllers\Api\V1\Entreprise;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use App\Services\Client\ClientSearchService;
use App\Services\RingCentralService;
use App\Support\EmployeeProfile;
use App\Support\PeriodFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EnterpriseController extends Controller
{
    /**
 * Identifiants RingCentral optionnels de l'entreprise, sans repli `.env`.
     */
    private const RINGCENTRAL_RULES = [
        'ringcentral_client_id' => 'nullable|string|max:255',
        'ringcentral_client_secret' => 'nullable|string|max:500',
        'ringcentral_token' => 'nullable|string|max:4000',
    ];

    /**
     * « Source » de l'entreprise — **choix pris dans la table `sources`**
     * (`GET /api/v1/sources`, répertoire fermé sans CRUD). Le libellé est
     * stocké en clair dans `enterprises.source` : ni clé étrangère ni
     * contrainte `exists`, pour ne jamais bloquer l'enregistrement si le
     * répertoire évolue.
     */
    private const SOURCE_RULES = ['source' => 'nullable|string|max:255'];

    /** Champs normalisés (trim, `''` → `null`) à la création comme à l'édition. */
    private const TRIMMABLE_FIELDS = [
        'ringcentral_client_id',
        'ringcentral_client_secret',
        'ringcentral_token',
        'source',
    ];

    public function __construct(private ClientSearchService $search) {}

    protected function formatEnterprise(Enterprise $enterprise): array
    {
        return [
            'id' => $enterprise->id,
            'name' => $enterprise->name,
            'email' => $enterprise->email,
            'phone' => $enterprise->phone,
            'tax_number' => $enterprise->tax_number,
            'address' => $enterprise->address,
            'logo' => $enterprise->logo,
            'logo_url' => EmployeeProfile::logoUrl($enterprise->logo),
            'status' => $enterprise->status ?? 'ACTIVE',
            'employees_count' => $enterprise->employees_count ?? 0,
            // La réponse générale garde secret/JWT masqués. Leur lecture
            // explicite passe par une route admin dédiée avec `no-store`.
            'ringcentral_client_id' => $enterprise->ringcentral_client_id,
            'ringcentral_client_secret_set' => filled($enterprise->ringcentral_client_secret),
            'ringcentral_token_set' => filled($enterprise->ringcentral_token),
            'ringcentral_configured' => $enterprise->hasOwnRingCentralAccount(),
            'source' => $enterprise->source,
            'created_at' => $enterprise->created_at,
            'updated_at' => $enterprise->updated_at,
        ];
    }

    /**
     * Normalise les champs optionnels reçus (RingCentral **et** source) :
     *
     *   - **absents** du payload → non retenus (la valeur enregistrée est
     *     conservée, ce qui rend les champs « optionnels » à la mise à jour) ;
     *   - `null` ou `''`         → `null` (valeur retirée) ;
     *   - valeur non vide        → enregistrée (espaces retirés).
     *
     * @return array<string, string|null>
     */
    private function normalizedAttributes(array $validated): array
    {
        $attributes = [];

        foreach (self::TRIMMABLE_FIELDS as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $value = trim((string) ($validated[$key] ?? ''));
            $attributes[$key] = $value === '' ? null : $value;
        }

        return $attributes;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Enterprise::withCount('employees');

        if ($search = $request->input('search')) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like)
                    ->orWhere('phone', 'LIKE', $like)
                    ->orWhere('tax_number', 'LIKE', $like)
                    ->orWhere('address', 'LIKE', $like);
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortable = [
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'tax_number' => 'tax_number',
            'status' => 'status',
            'created_at' => 'created_at',
            'employees_count' => 'employees_count',
        ];

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (array_key_exists($sortBy, $sortable)) {
            $query->orderBy($sortable[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = min((int) $request->input('per_page', 20), 300);
        $enterprises = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'entreprises' => $enterprises->getCollection()->map(fn ($e) => $this->formatEnterprise($e)),
                'pagination' => [
                    'current_page' => $enterprises->currentPage(),
                    'last_page' => $enterprises->lastPage(),
                    'per_page' => $enterprises->perPage(),
                    'total' => $enterprises->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'status' => 'nullable|in:ACTIVE,INACTIVE',
            ...self::RINGCENTRAL_RULES,
            ...self::SOURCE_RULES,
        ]);

        $enterprise = Enterprise::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'address' => $validated['address'] ?? null,
            'status' => $validated['status'] ?? 'ACTIVE',
            ...$this->normalizedAttributes($validated),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Entreprise créée avec succès.',
            'data' => [
                'entreprise' => $this->formatEnterprise($enterprise),
            ],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $enterprise = Enterprise::withCount('employees')->find($id);

        if (! $enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'entreprise' => $this->formatEnterprise($enterprise),
            ],
        ]);
    }

    public function checkRingCentralCredentials(string $id, RingCentralService $ringCentral): JsonResponse
    {
        $enterprise = Enterprise::findOrFail($id);

        if (! $enterprise->hasOwnRingCentralAccount()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'active' => false,
                    'reason' => 'credentials_missing',
                    'account' => null,
                    'phone_numbers' => [],
                    'phone_numbers_available' => false,
                    'extensions' => [],
                    'extensions_available' => false,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]);
        }

        $ringCentral->configure($enterprise->getRingCentralCredentials());

        try {
            $account = $ringCentral->getAccount();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'data' => [
                    'active' => false,
                    'reason' => 'connection_failed',
                    'account' => null,
                    'phone_numbers' => [],
                    'phone_numbers_available' => false,
                    'extensions' => [],
                    'extensions_available' => false,
                    'checked_at' => now()->toIso8601String(),
                    // Détail de l'échec (débogage) : message + statut HTTP +
                    // corps `error` / `error_description` renvoyé par
                    // RingCentral (`invalid_client` = client_id/secret,
                    // `invalid_grant` = JWT expiré/révoqué…).
                    'raw' => ['error' => $this->ringCentralError($e)],
                ],
            ]);
        }

        $phoneNumbersAvailable = true;
        $phoneNumbersError = null;
        try {
            $phoneNumbers = $ringCentral->getPhoneNumbers(1000);
        } catch (\Throwable $e) {
            $phoneNumbers = [];
            $phoneNumbersAvailable = false;
            $phoneNumbersError = $this->ringCentralError($e);
        }

        // 3. Postes / extensions — `GET /account/~/extension`
        //    (`type=User&status=Enabled&perPage=`) : dernière étape du
        //    contrôle (compte → numéros → postes), comme l'appel direct
        //    `GET /call-logs/users`. Best-effort : un échec n'invalide pas
        //    le compte, il est juste signalé (`extensions_available`).
        $extensionsAvailable = true;
        $extensionsError = null;
        $extensions = [];
        try {
            $extensions = $ringCentral->getAllUsers(100);
        } catch (\Throwable $e) {
            $extensions = [];
            $extensionsAvailable = false;
            $extensionsError = $this->ringCentralError($e);
        }

        $accountInfo = array_filter([
            'id' => data_get($account, 'id'),
            'company_name' => data_get($account, 'companyName'),
            'main_number' => data_get($account, 'mainNumber'),
            'status' => data_get($account, 'status'),
            'type' => data_get($account, 'type'),
        ], fn ($value) => $value !== null && $value !== '');

        return response()->json([
            'success' => true,
            'data' => [
                'active' => true,
                'reason' => null,
                'account' => $accountInfo,
                'phone_numbers' => collect($phoneNumbers)
                    ->map(fn ($number) => [
                        'number' => data_get($number, 'phoneNumber'),
                        'type' => data_get($number, 'type'),
                        'status' => data_get($number, 'status'),
                        'extension_id' => data_get($number, 'extension.id'),
                        'extension_number' => data_get($number, 'extension.extensionNumber'),
                    ])
                    ->filter(fn ($number) => filled($number['number']))
                    ->values(),
                'phone_numbers_available' => $phoneNumbersAvailable,
                'extensions' => collect($extensions)
                    ->map(fn ($extension) => [
                        'id' => data_get($extension, 'id'),
                        'extension_number' => data_get($extension, 'extensionNumber'),
                        'name' => data_get($extension, 'name'),
                        'status' => data_get($extension, 'status'),
                        'type' => data_get($extension, 'type'),
                        'permissions' => data_get($extension, 'permissions'),
                    ])
                    ->filter(fn ($extension) => filled($extension['id']))
                    ->values(),
                'extensions_available' => $extensionsAvailable,
                'checked_at' => now()->toIso8601String(),
                // Réponses **brutes** de l'API RingCentral (débogage).
                'raw' => array_merge(
                    [
                        'account' => $account,
                        'phone_numbers' => $phoneNumbers,
                        'extensions' => $extensions,
                    ],
                    $phoneNumbersAvailable ? [] : ['phone_numbers_error' => $phoneNumbersError],
                    $extensionsAvailable ? [] : ['extensions_error' => $extensionsError],
                ),
            ],
        ]);
    }

    /**
     * Détail d'une exception RingCentral (débogage) : message PHP + statut
     * HTTP + corps `error` / `error_description` renvoyé par l'API (source
     * réelle du blocage : `invalid_client`, `invalid_grant`, `CMN-301`…).
     */
    private function ringCentralError(\Throwable $e): array
    {
        $error = [
            'message' => $e->getMessage(),
            'status' => null,
            'body' => null,
            'hint' => 'Erreur locale PHP (aucune requête n\'est partie) — config:check les identifiants de l\'entreprise.',
        ];

        if (! method_exists($e, 'apiResponse')) {
            return $error;
        }

        $apiResponse = $e->apiResponse();
        $response = $apiResponse ? $apiResponse->response() : null;

        if (! $response) {
            return $error;
        }

        $error['status'] = $response->getStatusCode();

        $body = trim((string) $response->getBody());
        $decoded = $body === '' ? null : json_decode($body, true);

        $error['body'] = is_array($decoded) ? $decoded : ($body === '' ? null : $body);
        $error['hint'] = $this->ringCentralErrorHint($error['status'], $decoded);

        return $error;
    }

    /**
     * Quel(s) identifiant(s) est(sont) en cause selon l'erreur RingCentral :
     * c'est la réponse à « quel credential est le mauvais ? ».
     */
    private function ringCentralErrorHint(?int $status, ?array $body): string
    {
        $code = (string) (data_get($body, 'errors.0.errorCode') ?? data_get($body, 'error') ?? '');

        // JWT échangé contre un access_token : c'est le jeton qui est refusé.
        return match (true) {
            in_array($code, ['OAU-345', 'OAU-344', 'OAU-353', 'invalid_grant'], true) =>
                'JWT (`ringcentral_token`) invalide ou expiré — régénéré depuis l\'application RingCentral qui possède ce client_id/secret (même environnement : production vs sandbox).',
            $code === 'invalid_client' || $status === 401 =>
                'client_id / client_secret refusés — vérifier `ringcentral_client_id` et `ringcentral_client_secret` (et qu\'ils viennent du même environnement que le JWT).',
            $status === 429 =>
                'Limite de requêtes RingCentral atteinte (CMN-301) — réessayer dans quelques secondes, aucun identifiant n\'est en cause.',
            $code === 'CMN-102' || $status === 404 =>
                'Ressource introuvable sur ce compte — identifiants valides mais compte/extension différent.',
            default =>
                'Voir `message` / `body` ci-dessus.',
        };
    }

    public function ringCentralCredentials(string $id): JsonResponse
    {
        $enterprise = Enterprise::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'client_id' => $enterprise->ringcentral_client_id,
                'client_secret' => $enterprise->ringcentral_client_secret,
                'jwt' => $enterprise->ringcentral_token,
            ],
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /**
     * `PUT /entreprises/{id}/ringcentral-credentials` — **correction** des
     * identifiants RingCentral depuis l'onglet « RingCentral » de la fiche
     * entreprise (ADMIN / SUPER_ADMIN) : sémantique identique à `update()`
     * — clé absente = conservée, non vide = remplacée (trim), `''` / `null`
     * = retirée (`Enterprise::updateRingCentralCredentials()`).
     *
     * `no-store` : la réponse contient `ringcentral_client_id` (le secret /
     * le JWT ne reviennent jamais — seuls les indicateurs `*_set`).
     */
    public function updateRingCentralCredentials(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::findOrFail($id);

        $validated = $request->validate([
            'ringcentral_client_id' => 'sometimes|nullable|string|max:255',
            'ringcentral_client_secret' => 'sometimes|nullable|string|max:500',
            'ringcentral_token' => 'sometimes|nullable|string|max:4000',
        ]);

        $enterprise->updateRingCentralCredentials($validated);

        return response()->json([
            'success' => true,
            'message' => 'Identifiants RingCentral enregistrés.',
            'data' => [
                'entreprise' => $this->formatEnterprise($enterprise->fresh()),
            ],
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /**
     * `GET /entreprises/{id}/ringcentral-token` — échange le JWT de
     * l'entreprise contre un `access_token` OAuth (grant `jwt-bearer`,
     * via `RingCentralService::authenticate()`) et renvoie ses
     * métadonnées (valeur **masquée**, type, expiration) : première étape
     * de l'onglet « RingCentral » de la fiche entreprise, qui enchaîne
     * ensuite sur `ringcentral-status` (compte / numéros / postes) avec
     * ce même jeton réutilisé côté serveur (cache + `ringcentral_auth`).
     *
     * 422 si l'entreprise n'a pas de compte RingCentral complet en base ;
     * 502 + détail (`ringCentralError`) si RingCentral refuse l'échange.
     */
    public function ringCentralToken(string $id, RingCentralService $ringCentral): JsonResponse
    {
        $enterprise = Enterprise::findOrFail($id);

        if (! $enterprise->hasOwnRingCentralAccount()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette entreprise n\'a pas de compte RingCentral complet en base (client ID, secret et JWT requis).',
            ], 422);
        }

        $ringCentral->configure($enterprise->getRingCentralCredentials());

        try {
            $token = $ringCentral->accessTokenInfo();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Échange JWT → access_token refusé par RingCentral.',
                'error' => $this->ringCentralError($e),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $token,
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (! $enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'tax_number' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string',
            'status' => 'sometimes|in:ACTIVE,INACTIVE,ARCHIVED',
            ...self::RINGCENTRAL_RULES,
            ...self::SOURCE_RULES,
        ]);

        // Champs présents = remplacés ; champs absents (cas du formulaire qui
        // n'envoie pas un secret laissé vide) = valeur enregistrée conservée.
        $enterprise->update([
            ...$validated,
            ...$this->normalizedAttributes($validated),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Entreprise mise à jour.',
            'data' => [
                'entreprise' => $this->formatEnterprise($enterprise->fresh()),
            ],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (! $enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $enterprise->delete();

        return response()->json([
            'success' => true,
            'message' => 'Entreprise supprimée.',
        ]);
    }

    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (! $enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:ACTIVE,INACTIVE',
        ]);

        $enterprise->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Statut mis à jour.',
            'data' => [
                'entreprise' => $this->formatEnterprise($enterprise->fresh()),
            ],
        ]);
    }

    public function updateLogo(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (! $enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $request->validate([
            'logo' => 'required|image|max:2048|mimes:jpg,jpeg,png,gif,webp',
        ]);

        $oldLogo = $enterprise->logo;
        if ($oldLogo && Storage::disk('public')->exists("logos/{$oldLogo}")) {
            Storage::disk('public')->delete("logos/{$oldLogo}");
        }

        $file = $request->file('logo');
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().".{$extension}";

        $file->storeAs('logos', $filename, 'public');

        $enterprise->update(['logo' => $filename]);

        // URL **relative**, comme partout où une image est exposée : le
        // navigateur la reprend sur sa propre origine (proxy Vite en dev, nginx
        // en prod) au lieu du port de l'API.
        $url = EmployeeProfile::logoUrl($filename);

        return response()->json([
            'success' => true,
            'message' => 'Logo mis à jour.',
            'data' => [
                'logo' => $filename,
                'logo_url' => $url,
                'avatar_url' => $url,
                'entreprise' => $this->formatEnterprise($enterprise->fresh()),
            ],
        ]);
    }

    /**
     * Statistiques d'une entreprise **et de ses employés** — même principe
     * que la page de détails d'un employé (`GET /commercials/{id}`) :
     *
     *  - `analytics` : agrégats de l'entreprise (cartes KPI en haut) ;
     *  - `employees` : une ligne par employé avec **ses** chiffres ;
     *  - `historique` : clients appelés par un employé de l'entreprise,
     *    paginés, avec les **10 badges** de statut (`badges.by_display_status`)
     *    qui servent de filtre — exactement la forme de `historique` du
     *    détail employé, réutilisée par `<ProspectKpis>` / `<HistoryList>`.
     *
     * GET /entreprises/{id}/stats — ADMIN / SUPER_ADMIN.
     *
     * L'association client → entreprise n'existe plus en base
     * (`clients.enterprise_id` supprimé) : le périmètre est donc celui des
     * **employés** de l'entreprise (réservations + appels).
     *
     * Période de l'historique (`?year=&month=&week=&day=`, ou `?range=` =
     * fenêtre roulante) : **absente = instantané complet**. Posée, elle borne
     * `historique` **et** ses `badges` sur la date de la note d'appel des
     * employés — l'invariant §9 tient donc sous période. Les `analytics` et les
     * chiffres par employé restent des totaux de toute l'histoire.
     */
    public function stats(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::withCount('employees')->find($id);

        if (! $enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        // Employés = utilisateurs COMERCIAL rattachés via `employees.enterprise_id`.
        $userIds = User::where('role', 'COMERCIAL')
            ->whereHas('employee', fn ($q) => $q->where('enterprise_id', $enterprise->id))
            ->pluck('id')
            ->all();

        // ── Agrégats « par employé » : 5 requêtes groupées, jamais une
        //    requête par employé (une entreprise peut en avoir beaucoup).
        $callsByUser = Note::whereIn('sender_id', $userIds)->calls()
            ->selectRaw('sender_id, COUNT(*) AS total')
            ->groupBy('sender_id')
            ->pluck('total', 'sender_id');

        $ouiByUser = Note::whereIn('sender_id', $userIds)
            ->where('type', Note::TYPE_YES)
            ->selectRaw('sender_id, COUNT(*) AS total')
            ->groupBy('sender_id')
            ->pluck('total', 'sender_id');

        $ouiClientsByUser = Note::whereIn('sender_id', $userIds)
            ->where('type', Note::TYPE_YES)
            ->selectRaw('sender_id, COUNT(DISTINCT client_id) AS total')
            ->groupBy('sender_id')
            ->pluck('total', 'sender_id');

        $calledByUser = Note::whereIn('sender_id', $userIds)->calls()
            ->selectRaw('sender_id, COUNT(DISTINCT client_id) AS total')
            ->groupBy('sender_id')
            ->pluck('total', 'sender_id');

        $reservationsByUser = Reservation::whereIn('comercial_id', $userIds)
            ->selectRaw('comercial_id, COUNT(*) AS total')
            ->groupBy('comercial_id')
            ->pluck('total', 'comercial_id');

        $pendingByUser = Reservation::whereIn('comercial_id', $userIds)
            ->where('status', Reservation::STATUS_PENDING)
            ->selectRaw('comercial_id, COUNT(*) AS total')
            ->groupBy('comercial_id')
            ->pluck('total', 'comercial_id');

        $groupsByUser = ReservationGroup::whereIn('comercial_id', $userIds)
            ->selectRaw('comercial_id, COUNT(*) AS total')
            ->groupBy('comercial_id')
            ->pluck('total', 'comercial_id');

        // ── Agrégats « entreprise » : distincts sur tout le périmètre (un
        //    client appelé par deux employés n'est compté qu'une fois).
        $analytics = [
            'employees' => count($userIds),
            'groups' => (int) ReservationGroup::whereIn('comercial_id', $userIds)->count(),
            'reservations' => (int) Reservation::whereIn('comercial_id', $userIds)->count(),
            'pending' => (int) Reservation::whereIn('comercial_id', $userIds)
                ->where('status', Reservation::STATUS_PENDING)
                ->count(),
            'calls' => (int) Note::whereIn('sender_id', $userIds)->calls()->count(),
            'calls_oui' => (int) Note::whereIn('sender_id', $userIds)
                ->where('type', Note::TYPE_YES)
                ->count(),
            'clients_called' => (int) Note::whereIn('sender_id', $userIds)->calls()
                ->distinct()
                ->count('client_id'),
            'clients_oui' => (int) Note::whereIn('sender_id', $userIds)
                ->where('type', Note::TYPE_YES)
                ->distinct()
                ->count('client_id'),
        ];

        $employees = User::where('role', 'COMERCIAL')
            ->whereIn('id', $userIds)
            ->with('employee')
            ->orderBy('email')
            ->get()
            ->map(function (User $u) use ($callsByUser, $ouiByUser, $ouiClientsByUser, $calledByUser, $reservationsByUser, $pendingByUser, $groupsByUser) {
                $employee = $u->employee;
                $name = trim(($employee?->first_name ?? $u->first_name ?? '').' '.($employee?->last_name ?? $u->last_name ?? ''));

                return [
                    'id' => $u->id,
                    'name' => $name !== '' ? $name : $u->email,
                    'email' => $u->email,
                    'phone' => $employee?->phone ?? $u->phone,
                    'status' => $u->status,
                    'avatar_url' => EmployeeProfile::employeePhotoUrl($employee?->image_dp),
                    'groups' => (int) ($groupsByUser[$u->id] ?? 0),
                    'reservations' => (int) ($reservationsByUser[$u->id] ?? 0),
                    'pending' => (int) ($pendingByUser[$u->id] ?? 0),
                    'calls' => (int) ($callsByUser[$u->id] ?? 0),
                    'calls_oui' => (int) ($ouiByUser[$u->id] ?? 0),
                    'clients_called' => (int) ($calledByUser[$u->id] ?? 0),
                    'clients_oui' => (int) ($ouiClientsByUser[$u->id] ?? 0),
                ];
            })
            ->values();

        // ── Historique commun : mêmes filtres, badges et forme de lignes que
        //    l'onglet « Historique » du détail d'un employé.
        $perPage = min((int) $request->input('per_page', 10), 300);
        $page = max((int) $request->input('page', 1), 1);

        // Période (`PeriodFilter`) : absente = instantané, posée = bornes sur
        // la **date de la note d'appel** des employés de l'entreprise. La base
        // `historyBase()` alimente la liste **et** les badges → « compteur du
        // badge = lignes rendues » (§9) continue de tenir sous période.
        $period = PeriodFilter::fromRequest($request, defaultToCurrentYear: false);
        [$start, $end] = $period !== null ? PeriodFilter::bounds($period) : [null, null];

        $historyBase = function () use ($userIds, $request, $start, $end) {
            $query = Client::query()
                ->whereHas('notes', function ($q) use ($userIds, $start, $end) {
                    $q->whereIn('sender_id', $userIds)->calls();

                    if ($start !== null) {
                        $q->where('created_at', '>=', $start)->where('created_at', '<', $end);
                    }
                });

            if ($search = $request->input('search')) {
                $this->search->search($query, $search);
            }

            return $query;
        };

        // « Dernier appel » affiché par ligne : même fenêtre que le périmètre.
        $window = fn ($q) => $start === null
            ? $q
            : $q->where('created_at', '>=', $start)->where('created_at', '<', $end);

        $historyQuery = $historyBase()->with([
            'notes' => fn ($q) => $window($q->whereIn('sender_id', $userIds))
                ->with('sender:id,first_name,last_name,email')
                ->orderByDesc('created_at'),
            'currentReservation:id,status',
        ]);

        $this->search->applyDisplayStatusFilters(
            $historyQuery,
            $request->input('status'),
            $request->input('reservation_status')
        );

        $clientsPage = $historyQuery->paginate($perPage, ['*'], 'page', $page);

        $badges = [
            'prospects' => ['system' => $historyBase()->count()],
            'by_display_status' => $this->search->displayStatusCounts($historyBase),
        ];

        // Années du menu « Année » : celles où les employés de l'entreprise ont
        // appelé, lues **hors période** (le menu ne se rétrécit pas quand un
        // filtre est déjà posé).
        $years = PeriodFilter::yearsBetween(
            Note::whereIn('sender_id', $userIds)->calls()->min('created_at'),
            Note::whereIn('sender_id', $userIds)->calls()->max('created_at'),
        );

        Client::loadLatestReservations($clientsPage->getCollection());

        $historique = $clientsPage->getCollection()->map(function ($c) {
            $last = $c->notes->first();

            return [
                'id' => $c->id,
                'name' => $c->name ?? '—',
                'email' => $c->email,
                'phone' => $c->phone,
                'status' => $c->status,
                'display_status' => $c->displayStatus(),
                'reservation_status' => $c->currentReservation?->status,
                'is_blacklisted' => $c->is_blacklisted,
                'returned_at' => $c->returned_at,
                'municipality' => $c->municipality,
                'enterprise_name' => $c->enterprise_name ?? '—',
                'licence_end_date' => $c->licence_end_date,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
                'last_call' => $last ? [
                    'type' => $last->type,
                    'description' => $last->description,
                    'created_at' => $last->created_at,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'entreprise' => $this->formatEnterprise($enterprise),
                'analytics' => $analytics,
                'employees' => $employees,
                'historique' => [
                    'clients' => $historique,
                    'badges' => $badges,
                    'years' => $years,
                    'pagination' => [
                        'current_page' => $clientsPage->currentPage(),
                        'last_page' => $clientsPage->lastPage(),
                        'per_page' => $clientsPage->perPage(),
                        'total' => $clientsPage->total(),
                    ],
                ],
            ],
        ]);
    }
}
