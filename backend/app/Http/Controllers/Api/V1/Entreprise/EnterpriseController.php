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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EnterpriseController extends Controller
{
    public function __construct(private ClientSearchService $search) {}

    protected function formatEnterprise(Enterprise $enterprise): array
    {
        $base = request()->getSchemeAndHttpHost();

        return [
            'id' => $enterprise->id,
            'name' => $enterprise->name,
            'email' => $enterprise->email,
            'phone' => $enterprise->phone,
            'tax_number' => $enterprise->tax_number,
            'address' => $enterprise->address,
            'logo' => $enterprise->logo,
            'logo_url' => $enterprise->logo
                ? "{$base}/storage/logos/{$enterprise->logo}"
                : null,
            'status' => $enterprise->status ?? 'ACTIVE',
            'employees_count' => $enterprise->employees_count ?? 0,
            'created_at' => $enterprise->created_at,
            'updated_at' => $enterprise->updated_at,
        ];
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
        ]);

        $enterprise = Enterprise::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'address' => $validated['address'] ?? null,
            'status' => $validated['status'] ?? 'ACTIVE',
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
        ]);

        $enterprise->update($validated);

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

        $base = request()->getSchemeAndHttpHost();
        $url = "{$base}/storage/logos/{$filename}";

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
     *    paginés, avec les **8 badges** de statut (`badges.by_display_status`)
     *    qui servent de filtre — exactement la forme de `historique` du
     *    détail employé, réutilisée par `<ProspectKpis>` / `<HistoryList>`.
     *
     * GET /entreprises/{id}/stats — ADMIN / SUPER_ADMIN.
     *
     * L'association client → entreprise n'existe plus en base
     * (`clients.enterprise_id` supprimé) : le périmètre est donc celui des
     * **employés** de l'entreprise (réservations + appels).
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
                    'avatar_url' => $employee?->image_dp
                        ? request()->getSchemeAndHttpHost()."/storage/avatars/{$employee->image_dp}"
                        : null,
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

        $historyBase = function () use ($userIds, $request) {
            $query = Client::query()
                ->whereHas('notes', fn ($q) => $q->whereIn('sender_id', $userIds)->calls());

            if ($search = $request->input('search')) {
                $this->search->search($query, $search);
            }

            return $query;
        };

        $historyQuery = $historyBase()->with([
            'notes' => fn ($q) => $q->whereIn('sender_id', $userIds)
                ->with('sender:id,first_name,last_name')
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
