<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\AccountVerificationLinks;
use App\Support\EmployeeProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Standard Response Helpers
    |--------------------------------------------------------------------------
    */

    protected function respondOk($data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        $payload = ['success' => true];
        if ($message) {
            $payload['message'] = $message;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $code);
    }

    protected function respondError(string $message, int $code = 400, $errors = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }

    protected function notFound(string $message = 'Ressource introuvable.'): JsonResponse
    {
        return $this->respondError($message, 404);
    }

    protected function forbidden(string $message = 'Accès non autorisé.'): JsonResponse
    {
        return $this->respondError($message, 403);
    }

    /*
    |--------------------------------------------------------------------------
    | Role Hierarchy & Scope Helpers
    |--------------------------------------------------------------------------
    |
    | SUPER_ADMIN → manages ADMIN, COMERCIAL, OPERATOR
    | ADMIN        → manages COMERCIAL, OPERATOR (and other ADMINs for viewing)
    | COMERCIAL    → no user management (sees only self)
    | OPERATOR     → no user management (sees only self)
    |
    | Lower index = higher privilege: SUPER_ADMIN(0) > ADMIN(1) > COMERCIAL(2)
    | > OPERATOR(3).
    |
    */

    private const ROLE_HIERARCHY = [
        'SUPER_ADMIN' => 0,
        'ADMIN' => 1,
        'COMERCIAL' => 2,
        'OPERATOR' => 3,
    ];

    private const VALID_STATUSES = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];

    /**
     * Règle de saisie du nom d'utilisateur (voyant « Nom d'utilisateur »
     * des modales employé). La valeur est normalisée en minuscules par
     * `normalizeUsername()` avant validation ; la longueur minimale de 3
     * caractères s'applique donc à la valeur déjà retaillée.
     */
    private const USERNAME_RULES = 'sometimes|nullable|string|min:3|max:100|regex:/^[a-z0-9._-]+$/';

    /**
     * Build a scoped query filtered by the connected user's role.
     */
    protected function scopedQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = User::with(['employee.enterprise']);

        return match ($user->role) {
            'SUPER_ADMIN' => $query,
            // Les gestionnaires voient leur périmètre : ADMINs, commerciaux
            // et opérateurs (la page « Employés » liste `COMERCIAL,OPERATOR`).
            'ADMIN' => $query->whereIn('role', ['ADMIN', 'COMERCIAL', 'OPERATOR']),
            default => $query->where('id', $user->id),
        };
    }

    /**
     * Determine whether $actor may perform an action on $target.
     */
    protected function canAct(User $actor, User $target, string $action = 'view'): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        $actorRank = self::ROLE_HIERARCHY[$actor->role] ?? 99;
        $targetRank = self::ROLE_HIERARCHY[$target->role] ?? 99;

        if ($action === 'view') {
            return $actorRank <= $targetRank;
        }

        return $actorRank < $targetRank;
    }

    /*
    |--------------------------------------------------------------------------
    | User Formatter
    |--------------------------------------------------------------------------
    */

    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            // Nom d'utilisateur (identifiant court) — null pour les
            // comptes créés avant la colonne `users.username`.
            'username' => $user->username,
            'role' => $user->role,
            'status' => $user->status,
            // Privilège de libération (formater en bool : tinyint brut sinon).
            'has_permission' => (bool) $user->has_permission,
            // Colonne « Connexion hors zones » (page Employés) — dérogation
            // au géorepérage de connexion (`GeofenceService`, rôles contraints
            // uniquement : COMERCIAL / OPERATOR), **limitée à 24 h** : l'état
            // renvoyé est l'état *effectif* (expire le `gps_bypass_expires_at`),
            // pour que le switch montre ce que le serveur acceptera vraiment.
            'gps_bypass' => $user->isGpsBypassActive(),
            'gps_bypass_expires_at' => $user->gps_bypass_expires_at?->toIso8601String(),
            'avatar' => $user->avatar,
            'avatar_url' => EmployeeProfile::avatarUrl($user->avatar),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'created_at' => $user->created_at,
            'profil' => match ($user->role) {
                // Fiche employé — forme **partagée** avec la fiche d'un employé
                // (`CommercialAdminController::show()`), seule la modale à
                // produire : les deux réponses pré-remplissent `CommercialUpdateModal`.
                'COMERCIAL' => EmployeeProfile::forCommercial($user->employee),
                default => [
                    'prenom' => $user->first_name,
                    'nom' => $user->last_name,
                    'telephone' => $user->phone,
                ],
            },
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD: index
    |--------------------------------------------------------------------------
    |
    | GET /users
    | Query params: ?search=&role=&status=&page=&per_page=&sort_by=&sort_order=
    |
    */

    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedQuery($request);

        if ($search = $request->input('search')) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('email', 'LIKE', $like)
                    ->orWhere('first_name', 'LIKE', $like)
                    ->orWhere('last_name', 'LIKE', $like)
                    ->orWhereHas('employee', fn ($eq) => $eq->where('first_name', 'LIKE', $like)
                        ->orWhere('last_name', 'LIKE', $like)
                        ->orWhereHas('enterprise', fn ($entQ) => $entQ->where('name', 'LIKE', $like)));
            });
        }

        // Filtre de rôle : valeur unique (`?role=OPERATOR`) ou liste séparée
        // par virgules (`?role=COMERCIAL,OPERATOR` — page Employés).
        $roleValues = [];
        if ($role = $request->input('role')) {
            $roleValues = array_values(array_filter(array_map(
                fn ($value) => trim((string) $value),
                explode(',', (string) $role),
            )));
            if (count($roleValues) > 1) {
                $query->whereIn('role', $roleValues);
            } elseif ($roleValues !== []) {
                $query->where('role', $roleValues[0]);
            }
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortable = [
            'email' => 'users.email',
            'role' => 'users.role',
            'status' => 'users.status',
            'created_at' => 'users.created_at',
        ];

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'name') {
            if (in_array('COMERCIAL', $roleValues, true)) {
                $query->orderBy(
                    Employee::selectRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))")->whereColumn('employees.user_id', 'users.id'),
                    $sortOrder
                );
            } else {
                $query->orderBy('users.email', $sortOrder);
            }
        } elseif (array_key_exists($sortBy, $sortable)) {
            $query->orderBy($sortable[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('users.created_at');
        }

        $perPage = min((int) $request->input('per_page', 20), 300);
        $users = $query->paginate($perPage);

        return $this->respondOk([
            'utilisateurs' => $users->getCollection()->map(fn ($u) => $this->formatUser($u)),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | usernameAvailable
    |--------------------------------------------------------------------------
    |
    | GET /users/username-available?username=immed&id={uuid?}
    |
    | Contrôle en temps réel (debounce) de la modale employé : la colonne
    | `users.username` est unique, on renvoie donc `available` avant
    | l'envoi du formulaire. `id` = l'employé en cours d'édition, exclu de
    | la comparaison.
    |
    */

    public function usernameAvailable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:100',
            'id' => 'nullable|string|max:36',
        ]);

        $username = Str::lower(trim($validated['username']));
        // Miroir de la règle de saisie (`store` / `update`) : la syntaxe
        // fausse est signalée comme indisponible, sans erreur 422.
        $valid = (bool) preg_match('/^[a-z0-9._-]{3,100}$/', $username);

        $taken = User::where('username', $username)
            ->when($validated['id'] ?? '', fn ($query, $id) => $query->where('id', '!=', $id))
            ->exists();

        return $this->respondOk([
            'username' => $username,
            'valid' => $valid,
            'available' => $valid && ! $taken,
        ]);
    }

    /**
     * Normalise le nom d'utilisateur avant validation : minuscules +
     * espaces retirés, vide → null (la règle `nullable` accepte alors
     * l'effacement du champ en édition).
     */
    private function normalizeUsername(Request $request): void
    {
        $username = $request->input('username');
        if ($username === null) {
            return;
        }

        $normalized = is_string($username) ? Str::lower(trim($username)) : $username;

        $request->merge(['username' => $normalized === '' ? null : $normalized]);
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD: store
    |--------------------------------------------------------------------------
    |
    | POST /users
    |
    */

    public function store(Request $request, AccountVerificationLinks $verificationLinks): JsonResponse
    {
        $actor = $request->user();

        $this->normalizeUsername($request);

        $base = $request->validate([
            'email' => 'required|email|unique:users,email',
            // Commerciaux et opérateurs reçoivent un mot de passe posé par
            // l'admin (pas de flux de vérification par courriel).
            'mot_de_passe' => 'required_if:role,COMERCIAL,OPERATOR|nullable|string|min:8|confirmed',
            'role' => 'required|in:ADMIN,COMERCIAL,OPERATOR',
            // Nom d'utilisateur (identifiant court) — unique en base, la
            // modale vérifie la dispo en amont via `usernameAvailable`.
            'username' => self::USERNAME_RULES.'|unique:users,username',
        ]);

        $targetRank = self::ROLE_HIERARCHY[$base['role']] ?? 99;
        $actorRank = self::ROLE_HIERARCHY[$actor->role] ?? 99;
        if ($targetRank <= $actorRank) {
            return $this->forbidden('Vous ne pouvez pas créer un utilisateur de niveau égal ou supérieur.');
        }

        $profile = match ($base['role']) {
            'COMERCIAL' => $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:255',
                'additional_info' => 'nullable|string',
                'enterprise_id' => 'required|uuid|exists:enterprises,id',
                // Switch « Privilège commercial » (ex-« Privilège de
                // libération ») de la modale — colonne `users.has_permission`.
                'has_permission' => 'sometimes|boolean',
                // Switch « Connexion hors zones » (dérogation GPS,
                // `users.gps_bypass`) de la modale.
                'gps_bypass' => 'sometimes|boolean',
                // Source d'appel RingCentral choisie dans le select d'appareils.
                'ringcentral_device_id' => 'nullable|string|max:64',
                'ringcentral_extension_id' => 'nullable|string|max:64',
                'ringcentral_from_number' => 'nullable|string|max:32',
            ]),
            // Opérateur : simple identité + mot de passe — pas d'entreprise,
            // pas d'appareil RingCentral, pas de privilège de libération
            // (aucun enregistrement `employees` n'est créé).
            'OPERATOR' => $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:255',
            ]),
            'ADMIN' => $request->validate([
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:255',
            ]),
            default => [],
        };

        return DB::transaction(function () use ($base, $profile, $request, $verificationLinks) {
            $data = [
                'email' => $base['email'],
                // Nom d'utilisateur absent du payload → null (colonne
                // nullable, aucun effet de bord sur les autres comptes).
                'username' => $base['username'] ?? null,
                'role' => $base['role'],
                'first_name' => $profile['first_name'] ?? null,
                'last_name' => $profile['last_name'] ?? null,
                'phone' => $profile['phone'] ?? null,
                // Privilège de libération : absent du payload → false
                // (identique au défaut de colonne 0).
                'has_permission' => (bool) ($profile['has_permission'] ?? false),
                // La dérogation GPS n'est **pas** ici : elle passe par
                // `setGpsBypass()` après la création (échéance de 24 h).
            ];

            if (! empty($base['mot_de_passe'])) {
                $data['password_hash'] = Hash::make($base['mot_de_passe']);
                $data['email_verified_at'] = now();
            }

            $user = User::create($data);

            // Switch « Connexion hors zones » de la modale Créer : dérogation
            // **limitée à 24 h**, donc posée par `setGpsBypass()` (seul portail
            // qui écrit drapeau + échéance ensemble). Absente du payload → rien
            // n'est touché (défaut de colonne `false`).
            if (array_key_exists('gps_bypass', $profile)) {
                $user->setGpsBypass((bool) $profile['gps_bypass']);
            }

            if (empty($base['mot_de_passe'])) {
                $verificationLinks->send($user, $this->verificationDisplayName($profile, $user), $request);
            }

            if ($base['role'] === 'COMERCIAL') {
                Employee::create([
                    'user_id' => $user->id,
                    'enterprise_id' => $profile['enterprise_id'] ?? null,
                    'first_name' => $profile['first_name'],
                    'last_name' => $profile['last_name'],
                    'phone' => $profile['phone'] ?? null,
                    'additional_info' => $profile['additional_info'] ?? null,
                    'ringcentral_device_id' => $profile['ringcentral_device_id'] ?? null,
                    'ringcentral_extension_id' => $profile['ringcentral_extension_id'] ?? null,
                    'ringcentral_from_number' => $profile['ringcentral_from_number'] ?? null,
                ]);
            }

            return $this->respondOk(
                ['utilisateur' => $this->formatUser($user->fresh(['employee.enterprise']))],
                'Utilisateur créé avec succès.',
                201,
            );
        });
    }

    private function verificationDisplayName(array $profile, User $user): string
    {
        return trim(implode(' ', array_filter([
            $profile['first_name'] ?? $user->first_name,
            $profile['last_name'] ?? $user->last_name,
        ]))) ?: $user->email;
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD: show
    |--------------------------------------------------------------------------
    |
    | GET /users/{id}
    |
    */

    public function show(Request $request, string $id): JsonResponse
    {
        $target = User::with(['employee.enterprise'])->find($id);
        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canAct($request->user(), $target, 'view')) {
            return $this->forbidden();
        }

        return $this->respondOk(['utilisateur' => $this->formatUser($target)]);
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD: update
    |--------------------------------------------------------------------------
    |
    | PUT /users/{id}
    |
    */

    public function update(Request $request, string $id): JsonResponse
    {
        $target = User::with(['employee.enterprise'])->find($id);
        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canAct($request->user(), $target, 'update')) {
            return $this->forbidden();
        }

        $this->normalizeUsername($request);

        $data = $request->validate([
            'email' => 'sometimes|email|unique:users,email,'.$id,
            'mot_de_passe' => 'nullable|string|min:8|confirmed',
            'role' => 'sometimes|in:ADMIN,COMERCIAL,OPERATOR',
            'status' => 'sometimes|in:'.implode(',', self::VALID_STATUSES),
            // Nom d'utilisateur (identifiant court) : unique, hors
            // l'utilisateur en cours d'édition.
            'username' => self::USERNAME_RULES.'|unique:users,username,'.$id,
            // Switch « Privilège commercial » (ex-« Privilège de
            // libération ») de la modale — colonne `users.has_permission`
            // (jamais `employees`).
            'has_permission' => 'sometimes|boolean',
            // Switch « Connexion hors zones » (dérogation GPS,
            // `users.gps_bypass`) de la modale — colonne `users`, réglée à
            // `setGpsBypass()` (24 h) et donc sortie de `$data` plus bas.
            'gps_bypass' => 'sometimes|boolean',
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'additional_info' => 'sometimes|nullable|string',
            'enterprise_id' => 'sometimes|required|uuid|exists:enterprises,id',
            // Source d'appel RingCentral de l'employé (select d'appareils).
            'ringcentral_device_id' => 'sometimes|nullable|string|max:64',
            'ringcentral_extension_id' => 'sometimes|nullable|string|max:64',
            'ringcentral_from_number' => 'sometimes|nullable|string|max:32',
        ]);

        if (! empty($data['mot_de_passe'])) {
            $data['password_hash'] = Hash::make($data['mot_de_passe']);
            $data['email_verified_at'] = now();
            $data['verification_token'] = null;
            $data['verification_sent_at'] = null;
        }
        unset($data['mot_de_passe']);

        // Portés par `employees` (1:1 COMERCIAL), jamais par `users`.
        unset($data['ringcentral_device_id'], $data['ringcentral_extension_id'], $data['ringcentral_from_number']);

        // Dérogation GPS : sortie de `$data` — elle ne s'écrit que par
        // `setGpsBypass()`, qui repose une échéance de 24 h **même si** le
        // drapeau est déjà `true` mais expiré (réactivation depuis la modale).
        $gpsBypass = array_key_exists('gps_bypass', $data) ? (bool) $data['gps_bypass'] : null;
        unset($data['gps_bypass']);

        if (isset($data['role']) && $data['role'] !== $target->role) {
            $actorRank = self::ROLE_HIERARCHY[$request->user()->role] ?? 99;
            $targetRank = self::ROLE_HIERARCHY[$data['role']] ?? 99;
            if ($targetRank <= $actorRank) {
                return $this->forbidden('Impossible de promouvoir un utilisateur à un niveau égal ou supérieur.');
            }
        }

        $target->update($data);

        if ($gpsBypass !== null) {
            $target->setGpsBypass($gpsBypass);
        }

        if ($target->role === 'COMERCIAL' && $target->employee) {
            $target->employee->update($request->only([
                'first_name', 'last_name', 'phone', 'additional_info', 'enterprise_id',
                'ringcentral_device_id', 'ringcentral_extension_id', 'ringcentral_from_number',
            ]));
        }

        return $this->respondOk(
            ['utilisateur' => $this->formatUser($target->fresh(['employee.enterprise']))],
            'Utilisateur mis à jour.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD: destroy
    |--------------------------------------------------------------------------
    |
    | DELETE /users/{id}
    |
    */

    public function destroy(Request $request, string $id): JsonResponse
    {
        $target = User::find($id);
        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canAct($request->user(), $target, 'delete')) {
            return $this->forbidden();
        }

        if ($request->user()->id === $target->id) {
            return $this->forbidden('Vous ne pouvez pas supprimer votre propre compte.');
        }

        $target->delete();

        return $this->respondOk(null, 'Utilisateur supprimé.');
    }

    /*
    |--------------------------------------------------------------------------
    | toggleStatus
    |--------------------------------------------------------------------------
    |
    | PATCH /users/{id}/status
    | Body: { "status": "ACTIVE" | "INACTIVE" }
    |
    */

    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $target = User::find($id);
        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canAct($request->user(), $target, 'update')) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'status' => 'required|in:ACTIVE,INACTIVE',
        ]);

        $target->update(['status' => $validated['status']]);

        return $this->respondOk(
            ['utilisateur' => $this->formatUser($target->fresh(['employee.enterprise']))],
            'Statut mis à jour.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | updateGpsBypass
    |--------------------------------------------------------------------------
    |
    | PATCH /users/{id}/gps-bypass
    | Body: { "gps_bypass": true | false }
    |
    | Dérogation au géorepérage de connexion (colonne « Connexion hors zones »
    | de la page Employés). Seuls les rôles effectivement contraints
    | (`config/geofence.enforced_roles`) peuvent être réglés : un gestionnaire
    | n'est jamais bloqué, sa valeur n'a donc aucun sens.
    |
    | L'activation est **limitée à 24 h** (`User::setGpsBypass()` : échéance
    | `gps_bypass_expires_at` reposée à chaque clic) ; le cron
    | `gps:expire-bypass` remet ensuite le drapeau à `false`, mais le refus est
    | immédiat côté serveur dès l'échéance (`User::isGpsBypassActive()`).
    |
    */

    public function updateGpsBypass(Request $request, string $id): JsonResponse
    {
        $target = User::find($id);
        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canAct($request->user(), $target, 'update')) {
            return $this->forbidden();
        }

        $enforced = (array) config('geofence.enforced_roles', []);
        if (! in_array($target->role, $enforced, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Ce rôle n\'est pas soumis au géorepérage.',
            ], 422);
        }

        $validated = $request->validate([
            'gps_bypass' => 'required|boolean',
        ]);

        $active = (bool) $validated['gps_bypass'];
        // Un seul portail pour le drapeau + son échéance de 24 h (réactiver
        // repose une échéance fraîche, même après expiration).
        $target->setGpsBypass($active);
        $target->refresh();

        return $this->respondOk(
            ['utilisateur' => $this->formatUser($target->fresh(['employee.enterprise']))],
            $active
                ? sprintf(
                    'Connexion hors zones autorisée 24 h (expire le %s).',
                    $target->gps_bypass_expires_at?->format('d/m/Y H:i') ?? ''
                )
                : 'Connexion hors zones refusée.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | updatePrivilege
    |--------------------------------------------------------------------------
    |
    | PATCH /users/{id}/privilege
    | Body: { "has_permission": true | false }
    |
    | Bascule du « Privilège commercial » (colonne `users.has_permission`,
    | page Employés) — §7.1 : ne protège **que** la libération de la liste
    | (`POST reservations/release-pending`, middleware `CheckPermission`).
    | Sans effet sur la visibilité des « Double », visibles par tous. Le
    | drapeau n'a de sens que pour un COMERCIAL : un gestionnaire passe
    | toujours (hiérarchie), un OPÉRATEUR n'a rien à libérer — refus 422.
    |
    */

    public function updatePrivilege(Request $request, string $id): JsonResponse
    {
        $target = User::find($id);
        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canAct($request->user(), $target, 'update')) {
            return $this->forbidden();
        }

        if ($target->role !== 'COMERCIAL') {
            return response()->json([
                'success' => false,
                'message' => 'Seul un commercial peut recevoir le privilège de libération.',
            ], 422);
        }

        $validated = $request->validate([
            'has_permission' => 'required|boolean',
        ]);

        $target->update(['has_permission' => (bool) $validated['has_permission']]);

        return $this->respondOk(
            ['utilisateur' => $this->formatUser($target->fresh(['employee.enterprise']))],
            $target->has_permission ? 'Privilège de libération accordé.' : 'Privilège de libération retiré.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | updateAvatar
    |--------------------------------------------------------------------------
    |
    | POST /users/{id}/avatar
    | Body: multipart/form-data  { avatar: <file> }
    |
    */

    public function updateAvatar(Request $request, string $id): JsonResponse
    {
        $target = User::with(['employee.enterprise'])->find($id);
        if (! $target) {
            return $this->notFound();
        }

        if (! $this->canAct($request->user(), $target, 'update')) {
            return $this->forbidden();
        }

        $request->validate([
            'avatar' => 'required|image|max:2048|mimes:jpg,jpeg,png,gif,webp',
        ]);

        [$directory, $field, $model] = match (true) {
            $target->role === 'COMERCIAL' && $target->employee => ['avatars', 'image_dp', $target->employee],
            default => ['avatars', 'avatar', $target],
        };

        $oldFile = $model->{$field};
        if ($oldFile && Storage::disk('public')->exists("{$directory}/{$oldFile}")) {
            Storage::disk('public')->delete("{$directory}/{$oldFile}");
        }

        $file = $request->file('avatar');
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().".{$extension}";

        $file->storeAs($directory, $filename, 'public');

        $model->update([$field => $filename]);

        // Sync avatar between User and Employee for commercial users
        if ($target->role === 'COMERCIAL' && $target->employee) {
            if ($field === 'image_dp') {
                $target->update(['avatar' => $filename]);
            } elseif ($field === 'avatar') {
                $target->employee->update(['image_dp' => $filename]);
            }
        }

        // Chemin relatif : l'image est relue sur l'origine du navigateur (proxy
        // Vite en dev, nginx en prod), pas sur le port de l'API.
        $url = "/storage/{$directory}/{$filename}";

        return $this->respondOk(
            [
                'avatar_url' => $url,
                'avatar' => $filename,
            ],
            'Avatar mis à jour.',
        );
    }
}
