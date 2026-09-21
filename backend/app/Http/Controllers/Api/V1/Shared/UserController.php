<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\AccountVerificationLinks;
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
        if ($message) $payload['message'] = $message;
        if ($data !== null) $payload['data'] = $data;
        return response()->json($payload, $code);
    }

    protected function respondError(string $message, int $code = 400, $errors = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors) $payload['errors'] = $errors;
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
    | SUPER_ADMIN → manages ADMIN, COMERCIAL
    | ADMIN        → manages COMERCIAL (and other ADMINs for viewing)
    | COMERCIAL    → no user management (sees only self)
    |
    | Lower index = higher privilege: SUPER_ADMIN(0) > ADMIN(1) > COMERCIAL(2).
    |
    */

    private const ROLE_HIERARCHY = [
        'SUPER_ADMIN' => 0,
        'ADMIN'       => 1,
        'COMERCIAL'   => 2,
    ];

    private const VALID_STATUSES = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];

    /**
     * Build a scoped query filtered by the connected user's role.
     */
    protected function scopedQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $user  = $request->user();
        $query = User::with(['employee.enterprise']);

        return match ($user->role) {
            'SUPER_ADMIN' => $query,
            'ADMIN'       => $query->whereIn('role', ['ADMIN', 'COMERCIAL']),
            default       => $query->where('id', $user->id),
        };
    }

    /**
     * Determine whether $actor may perform an action on $target.
     */
    protected function canAct(User $actor, User $target, string $action = 'view'): bool
    {
        if ($actor->id === $target->id) return true;

        $actorRank  = self::ROLE_HIERARCHY[$actor->role]  ?? 99;
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
        $base = request()->getSchemeAndHttpHost();

        return [
            'id'         => $user->id,
            'email'      => $user->email,
            'role'       => $user->role,
            'status'     => $user->status,
            'avatar'     => $user->avatar,
            'avatar_url' => $user->avatar
                ? "{$base}/storage/avatars/{$user->avatar}"
                : null,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'phone'      => $user->phone,
            'created_at' => $user->created_at,
            'profil'     => match ($user->role) {
                'COMERCIAL' => $user->employee ? [
                    'id'              => $user->employee->id,
                    'prenom'          => $user->employee->first_name,
                    'nom'             => $user->employee->last_name,
                    'telephone'       => $user->employee->phone,
                    'entreprise_id'   => $user->employee->enterprise_id,
                    'entreprise_name' => $user->employee->enterprise?->name,
                    'image_dp'        => $user->employee->image_dp,
                    'image_dp_url'    => $user->employee->image_dp
                        ? "{$base}/storage/avatars/{$user->employee->image_dp}"
                        : null,
                    'info_supp'       => $user->employee->additional_info,
                    'entreprise'      => $user->employee->enterprise ? [
                        'name'       => $user->employee->enterprise->name,
                        'email'      => $user->employee->enterprise->email,
                        'tax_number' => $user->employee->enterprise->tax_number,
                        'phone'      => $user->employee->enterprise->phone,
                        'address'    => $user->employee->enterprise->address,
                        'status'     => $user->employee->enterprise->status,
                        'logo_url'   => $user->employee->enterprise->logo
                            ? "{$base}/storage/logos/{$user->employee->enterprise->logo}"
                            : null,
                    ] : null,
                ] : null,
                default => [
                    'prenom'    => $user->first_name,
                    'nom'       => $user->last_name,
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

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortable = [
            'email'      => 'users.email',
            'role'       => 'users.role',
            'status'     => 'users.status',
            'created_at' => 'users.created_at',
        ];

        $sortBy    = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'name') {
            $role = $request->input('role');
            if ($role === 'COMERCIAL') {
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

        $perPage = min((int) $request->input('per_page', 20), 100);
        $users   = $query->paginate($perPage);

        return $this->respondOk([
            'utilisateurs' => $users->getCollection()->map(fn ($u) => $this->formatUser($u)),
            'pagination'   => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ],
        ]);
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

        $base = $request->validate([
            'email'        => 'required|email|unique:users,email',
            'mot_de_passe' => 'nullable|string|min:8|confirmed',
            'role'         => 'required|in:ADMIN,COMERCIAL',
        ]);

        $targetRank = self::ROLE_HIERARCHY[$base['role']] ?? 99;
        $actorRank  = self::ROLE_HIERARCHY[$actor->role]  ?? 99;
        if ($targetRank <= $actorRank) {
            return $this->forbidden('Vous ne pouvez pas créer un utilisateur de niveau égal ou supérieur.');
        }

        $profile = match ($base['role']) {
            'COMERCIAL' => $request->validate([
                'first_name'      => 'required|string|max:255',
                'last_name'       => 'required|string|max:255',
                'phone'           => 'nullable|string|max:255',
                'additional_info' => 'nullable|string',
                'enterprise_id'   => 'nullable|uuid|exists:enterprises,id',
            ]),
            'ADMIN' => $request->validate([
                'first_name' => 'nullable|string|max:255',
                'last_name'  => 'nullable|string|max:255',
                'phone'      => 'nullable|string|max:255',
            ]),
            default => [],
        };

        return DB::transaction(function () use ($actor, $base, $profile, $request, $verificationLinks) {
            $data = [
                'email'      => $base['email'],
                'role'       => $base['role'],
                'first_name' => $profile['first_name'] ?? null,
                'last_name'  => $profile['last_name']  ?? null,
                'phone'      => $profile['phone']      ?? null,
            ];

            if (!empty($base['mot_de_passe'])) {
                $data['password_hash'] = Hash::make($base['mot_de_passe']);
                $data['email_verified_at'] = now();
            }

            $user = User::create($data);

            if (empty($base['mot_de_passe'])) {
                $verificationLinks->send($user, $this->verificationDisplayName($profile, $user), $request);
            }

            if ($base['role'] === 'COMERCIAL') {
                Employee::create([
                    'user_id'         => $user->id,
                    'enterprise_id'   => $profile['enterprise_id'] ?? null,
                    'first_name'      => $profile['first_name'],
                    'last_name'       => $profile['last_name'],
                    'phone'           => $profile['phone']           ?? null,
                    'additional_info' => $profile['additional_info'] ?? null,
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
        if (!$target) return $this->notFound();

        if (!$this->canAct($request->user(), $target, 'view')) {
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
        if (!$target) return $this->notFound();

        if (!$this->canAct($request->user(), $target, 'update')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'email'           => 'sometimes|email|unique:users,email,' . $id,
            'mot_de_passe'    => 'nullable|string|min:8',
            'role'            => 'sometimes|in:ADMIN,COMERCIAL',
            'status'          => 'sometimes|in:' . implode(',', self::VALID_STATUSES),
            'first_name'      => 'sometimes|nullable|string|max:255',
            'last_name'       => 'sometimes|nullable|string|max:255',
            'phone'           => 'sometimes|nullable|string|max:255',
            'additional_info' => 'sometimes|nullable|string',
            'enterprise_id'   => 'sometimes|nullable|uuid|exists:enterprises,id',
        ]);

        if (!empty($data['mot_de_passe'])) {
            $data['password_hash'] = Hash::make($data['mot_de_passe']);
        }
        unset($data['mot_de_passe']);

        if (isset($data['role']) && $data['role'] !== $target->role) {
            $actorRank  = self::ROLE_HIERARCHY[$request->user()->role]  ?? 99;
            $targetRank = self::ROLE_HIERARCHY[$data['role']]           ?? 99;
            if ($targetRank <= $actorRank) {
                return $this->forbidden('Impossible de promouvoir un utilisateur à un niveau égal ou supérieur.');
            }
        }

        $target->update($data);

        if ($target->role === 'COMERCIAL' && $target->employee) {
            $target->employee->update($request->only([
                'first_name', 'last_name', 'phone', 'additional_info', 'enterprise_id',
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
        if (!$target) return $this->notFound();

        if (!$this->canAct($request->user(), $target, 'delete')) {
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
        if (!$target) return $this->notFound();

        if (!$this->canAct($request->user(), $target, 'update')) {
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
        if (!$target) return $this->notFound();

        if (!$this->canAct($request->user(), $target, 'update')) {
            return $this->forbidden();
        }

        $request->validate([
            'avatar' => 'required|image|max:2048|mimes:jpg,jpeg,png,gif,webp',
        ]);

        [$directory, $field, $model] = match (true) {
            $target->role === 'COMERCIAL' && $target->employee => ['avatars', 'image_dp', $target->employee],
            default                                            => ['avatars', 'avatar', $target],
        };

        $oldFile = $model->{$field};
        if ($oldFile && Storage::disk('public')->exists("{$directory}/{$oldFile}")) {
            Storage::disk('public')->delete("{$directory}/{$oldFile}");
        }

        $file      = $request->file('avatar');
        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . ".{$extension}";

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

        $url = request()->getSchemeAndHttpHost() . "/storage/{$directory}/{$filename}";

        return $this->respondOk(
            [
                'avatar_url' => $url,
                'avatar'     => $filename,
            ],
            'Avatar mis à jour.',
        );
    }
}
