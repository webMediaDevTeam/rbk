<?php
namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\CallOutcome;

class CommercialAdminController extends Controller
{
    public function __construct(private \App\Services\CallWorkflowService $workflow)
    {
    }

    public function index()
    {
        $users = User::where('role', 'COMERCIAL')->with('employee')->get();
        $data = $users->map(function ($u) {
            $res = Reservation::where('comercial_id', $u->id)->count();
            $calls = CallOutcome::where('comercial_id', $u->id)->count();
            $callsOui = CallOutcome::where('comercial_id', $u->id)->where('outcome', 'OUI')->count();
            return [
                'id' => $u->id,
                'name' => trim(($u->employee?->first_name ?? $u->first_name ?? '') . ' ' . ($u->employee?->last_name ?? $u->last_name ?? '')) ?: $u->email,
                'email' => $u->email,
                'reservations' => $res,
                'calls' => $calls,
                'calls_oui' => $callsOui,
            ];
        });
        return response()->json(['data' => $data]);
    }

    public function show(Request $request, $id)
    {
        $user = User::where('role', 'COMERCIAL')->with('employee.enterprise')->findOrFail($id);
        $base = request()->getSchemeAndHttpHost();
        $ent = $user->employee?->enterprise;

        $groups = ReservationGroup::where('comercial_id', $id)->count();
        $reservations = Reservation::where('comercial_id', $id)->count();
        $calls = CallOutcome::where('comercial_id', $id)->count();
        $callsOui = CallOutcome::where('comercial_id', $id)->where('outcome', 'OUI')->count();
        $clientsCalled = CallOutcome::where('comercial_id', $id)->distinct()->count('client_id');
        $clientsOui = CallOutcome::where('comercial_id', $id)->where('outcome', 'OUI')->distinct()->count('client_id');

        $perPage = min((int) $request->input('per_page', 10), 300);
        $page = max((int) $request->input('page', 1), 1);

        $historyQuery = Client::query()
            ->whereHas('callOutcomes', fn ($q) => $q->where('comercial_id', $id))
            ->with(['callOutcomes' => fn ($q) => $q->where('comercial_id', $id)->orderByDesc('created_at')]);

        if ($search = $request->input('search')) {
            $like = "%{$search}%";
            $historyQuery->where(function ($q) use ($like) {
                $q->where('rbq_data->name', 'LIKE', $like)
                  ->orWhere('email', 'LIKE', $like)
                  ->orWhere('phone', 'LIKE', $like)
                  ->orWhere('municipality', 'LIKE', $like);
            });
        }

        $clientsPage = $historyQuery->paginate($perPage, ['*'], 'page', $page);

        $historique = $clientsPage->getCollection()->map(function ($c) {
            $last = $c->callOutcomes->first();
            return [
                'id' => $c->id,
                'name' => $c->rbq_data['name'] ?? '—',
                'email' => $c->email,
                'phone' => $c->phone,
                'status' => $c->status,
                'is_blacklisted' => $c->is_blacklisted,
                'municipality' => $c->municipality,
                'enterprise_name' => $c->rbq_data['entreprise_name'] ?? '—',
                'licence_end_date' => $c->licence_end_date,
                'created_at' => $c->created_at,
                'last_call' => $last ? [
                    'outcome' => $last->outcome,
                    'note' => $last->note,
                    'created_at' => $last->created_at,
                ] : null,
            ];
        });

        return response()->json([
            'user' => $user,
            'analytics' => [
                'groups' => $groups,
                'reservations' => $reservations,
                'calls' => $calls,
                'calls_oui' => $callsOui,
                'clients_called' => $clientsCalled,
                'clients_oui' => $clientsOui,
            ],
            'employee' => [
                'prenom' => $user->employee?->first_name ?? $user->first_name,
                'nom'    => $user->employee?->last_name ?? $user->last_name,
                'telephone' => $user->employee?->phone ?? $user->phone,
                'image_dp'  => $user->employee?->image_dp,
                'image_dp_url' => $user->employee?->image_dp
                    ? "{$base}/storage/avatars/{$user->employee->image_dp}" : null,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'additional_info' => $user->employee?->additional_info,
                'cree_le' => $user->created_at,
            ],
            'entreprise' => $ent ? [
                'id' => $ent->id,
                'name' => $ent->name,
                'email' => $ent->email,
                'tax_number' => $ent->tax_number,
                'phone' => $ent->phone,
                'address' => $ent->address,
                'status' => $ent->status,
                'logo_url' => $ent->logo ? "{$base}/storage/logos/{$ent->logo}" : null,
            ] : null,
            'historique' => [
                'clients' => $historique,
                'pagination' => [
                    'current_page' => $clientsPage->currentPage(),
                    'last_page'    => $clientsPage->lastPage(),
                    'per_page'     => $clientsPage->perPage(),
                    'total'        => $clientsPage->total(),
                ],
            ],
        ]);
    }

    /**
     * Admin list of clients that have interaction history (call outcomes).
     * GET /commercials/clients
     */
    public function clients(Request $request)
    {
        $query = Client::query()
            ->whereHas('callOutcomes')
            ->with(['callOutcomes' => fn ($q) => $q->orderByDesc('created_at')]);

        if ($search = $request->input('search')) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('rbq_data->name', 'LIKE', $like)
                  ->orWhere('rbq_data->entreprise_name', 'LIKE', $like)
                  ->orWhere('email', 'LIKE', $like)
                  ->orWhere('phone', 'LIKE', $like)
                  ->orWhere('municipality', 'LIKE', $like)
                  ->orWhere('neq', 'LIKE', $like)
                  ->orWhere('licence_number', 'LIKE', $like)
                  ->orWhereRaw('CAST(respondents AS CHAR) LIKE ?', [$like])
                  ->orWhereRaw('CAST(categories AS CHAR) LIKE ?', [$like])
                  ->orWhereRaw('CAST(authorized_categories AS CHAR) LIKE ?', [$like]);
            });
        }

        // Aucun filtre de date : les champs « Du / Au » ont été supprimés.

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortable = [
            'name'             => 'rbq_data->name',
            'email'            => 'email',
            'phone'            => 'phone',
            'status'           => 'status',
            'municipality'     => 'municipality',
            'created_at'       => 'created_at',
            'licence_end_date' => 'licence_end_date',
        ];
        $sortBy    = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (array_key_exists($sortBy, $sortable)) {
            $query->orderBy($sortable[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = min((int) $request->input('per_page', 10), 300);
        $page = max((int) $request->input('page', 1), 1);
        $clientsPage = $query->paginate($perPage, ['*'], 'page', $page);

        $clients = $clientsPage->getCollection()->map(function ($c) {
            $last = $c->callOutcomes->first();
            return [
                'id' => $c->id,
                'name' => $c->rbq_data['name'] ?? '—',
                'email' => $c->email,
                'phone' => $c->phone,
                'status' => $c->status,
                'is_blacklisted' => $c->is_blacklisted,
                'returned_at' => $c->returned_at,
                'municipality' => $c->municipality,
                'neq' => $c->neq,
                'licence_number' => $c->licence_number,
                'categories' => $c->categories,
                'respondents' => $c->respondents,
                'respondent_count' => $c->respondent_count,
                'authorized_categories' => $c->authorized_categories,
                'enterprise_name' => $c->rbq_data['entreprise_name'] ?? '—',
                'licence_end_date' => $c->licence_end_date,
                'created_at' => $c->created_at,
                'last_call' => $last ? [
                    'outcome' => $last->outcome,
                    'created_at' => $last->created_at,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'clients' => $clients,
                'pagination' => [
                    'current_page' => $clientsPage->currentPage(),
                    'last_page'    => $clientsPage->lastPage(),
                    'per_page'     => $clientsPage->perPage(),
                    'total'        => $clientsPage->total(),
                ],
            ],
        ]);
    }

    /**
     * Admin view of a single client (read-only data + interactions).
     * GET /commercials/clients/{id}
     */
    public function client(Request $request, $id)
    {
        $client = Client::with(['reservations.comercial', 'notes.comercial', 'callOutcomes.comercial'])->find($id);
        if (!$client) {
            return response()->json(['message' => 'Client introuvable.'], 404);
        }

        $activeReservation = $client->reservations->sortByDesc('created_at')->first();
        $assigned = $activeReservation?->comercial;

        return response()->json([
            'success' => true,
            'data' => [
                'client' => [
                    'id' => $client->id,
                    'name' => $client->rbq_data['name'] ?? '—',
                    'email' => $client->email,
                    'phone' => $client->phone,
                    'status' => $client->status,
                    'is_blacklisted' => $client->is_blacklisted,
                    'returned_at' => $client->returned_at,
                    'municipality' => $client->municipality,
                    'administrative_region' => $client->administrative_region,
                    'licence_number' => $client->licence_number,
                    'licence_propre_numero' => $client->licence_propre_numero,
                    'licence_status' => $client->licence_status,
                    'licence_end_date' => $client->licence_end_date,
                    'licence_start_date' => $client->licence_start_date,
                    'intervenant_name' => $client->intervenant_name,
                    'licence_propre' => $client->licence_propre,
                    'enterprise_name' => $client->rbq_data['entreprise_name'] ?? '—',
                    'neq' => $client->neq,
                    'full_address' => $client->full_address,
                    'categories' => $client->categories,
                    'categories_id' => $client->categories_id,
                    'respondent_count' => $client->respondent_count,
                    'respondents' => $client->respondents,
                    'sub_category_count' => $client->sub_category_count,
                    'authorized_categories' => $client->authorized_categories,
                    'surety_company' => $client->surety_company,
                    'cautionnement_compagnie' => $client->cautionnement_compagnie,
                    'surety_amount' => $client->surety_amount,
                    'rbq_data' => $client->rbq_data,
                    'representative_name' => $client->representative_name,
                    'assigned_commercial' => $assigned ? [
                        'id' => $assigned->id,
                        'email' => $assigned->email,
                        'first_name' => $assigned->first_name,
                        'last_name' => $assigned->last_name,
                    ] : null,
                    'reservations_count' => $client->reservations->count(),
                    'notes_count' => $client->notes->count(),
                    'my_reservation' => null,
                    'notes' => $client->notes->map(fn ($n) => [
                        'id' => $n->id,
                        'type' => $n->type,
                        'content' => $n->content,
                        'due_date' => $n->due_date,
                        'call_duration_seconds' => $n->call_duration_seconds,
                        'created_at' => $n->created_at,
                        'comercial' => $n->comercial ? [
                            'id' => $n->comercial->id,
                            'first_name' => $n->comercial->first_name,
                            'last_name' => $n->comercial->last_name,
                        ] : null,
                    ]),
                    'call_outcomes' => $client->callOutcomes->map(fn ($o) => [
                        'id' => $o->id,
                        'outcome' => $o->outcome,
                        'note' => $o->note,
                        'recall_amount' => $o->recall_amount,
                        'recall_unit' => $o->recall_unit,
                        'created_at' => $o->created_at,
                        'comercial' => $o->comercial ? [
                            'id' => $o->comercial->id,
                            'first_name' => $o->comercial->first_name,
                            'last_name' => $o->comercial->last_name,
                        ] : null,
                    ]),
                ],
            ],
        ]);
    }

    /**
     * Admin blacklist of a client.
     * POST /commercials/clients/{id}/blacklist
     */
    public function blacklist(Request $request, $id)
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:5000',
        ]);

        $client = Client::findOrFail($id);

        return DB::transaction(function () use ($client, $request, $validated) {
            $this->workflow->apply($client, null, 'BLACKLIST', $validated, $request->user());

            $client->update([
                'is_blacklisted' => true,
                'status' => 'BLACKLISTED',
                'returned_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client mis en liste noire.',
            ]);
        });
    }
}
