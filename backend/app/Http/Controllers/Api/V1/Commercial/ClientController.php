<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Services\CallWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function __construct(private CallWorkflowService $workflow)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Client::query();

        // Filtres = scopes Eloquent, sur les valeurs distinctes de la table
        // clients (plus de table categories).
        if ($municipality = $request->input('municipality')) {
            $query->filterByMunicipalities($municipality);
        }

        if ($category = $request->input('category')) {
            $query->filterByCategories($category);
        }

        if ($region = $request->input('administrative_region')) {
            $query->filterByAdministrativeRegions($region);
        }

        if ($search = $request->input('search')) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                  ->orWhere('enterprise_name', 'LIKE', $like)
                  ->orWhere('email', 'LIKE', $like)
                  ->orWhere('phone', 'LIKE', $like)
                  ->orWhere('neq', 'LIKE', $like)
                  ->orWhere('municipality', 'LIKE', $like)
                  ->orWhere('licence_number', 'LIKE', $like)
                  ->orWhere('licence_propre_numero', 'LIKE', $like)
                  // Colonnes JSON : sous-chaîne via le scope dédié
                  // (voir Client::scopeOrWhereJsonTextLike).
                  ->orWhereJsonTextLike('respondents', $like)
                  ->orWhereJsonTextLike('categories', $like)
                  ->orWhereJsonTextLike('authorized_categories', $like);
            });
        }

        // Aucun filtre de date : les champs « Du / Au » ont été supprimés.

        $query->where('is_blacklisted', false)
              ->available()
              ->whereDoesntHave('reservations', fn ($q) => $q->active());

        $sortable = [
            'name'        => 'name',
            'enterprise_name' => 'enterprise_name',
            'email'       => 'email',
            'phone'       => 'phone',
            'status'      => 'status',
            'municipality' => 'municipality',
            'created_at'  => 'created_at',
            'updated_at'  => 'updated_at',
            'licence_end_date' => 'licence_end_date',
        ];

        $sortBy    = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (array_key_exists($sortBy, $sortable)) {
            $query->orderBy($sortable[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = min((int) $request->input('per_page', 20), 300);
        $clients = $query->paginate($perPage);

        // Statut affiché : dernière réservation de chaque client en 1 requête
        // (sinon une requête par ligne à formatter).
        Client::loadLatestReservations($clients->getCollection());

        $formatted = $clients->getCollection()->map(fn ($client) => $this->formatClient($client));

        return response()->json([
            'success' => true,
            'data' => [
                'clients' => $formatted,
                'pagination' => [
                    'current_page' => $clients->currentPage(),
                    'last_page'    => $clients->lastPage(),
                    'per_page'     => $clients->perPage(),
                    'total'        => $clients->total(),
                ],
            ],
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Client::query()
            ->whereHas('reservations', function ($q) use ($user) {
                $q->where('comercial_id', $user->id);
            });

        $perPage = min((int) $request->input('per_page', 20), 300);
        $clients = $query->paginate($perPage);

        // Statut affiché : dernière réservation de chaque client en 1 requête
        // (sinon une requête par ligne à formatter).
        Client::loadLatestReservations($clients->getCollection());

        $formatted = $clients->getCollection()->map(fn ($client) => $this->formatClient($client));

        return response()->json([
            'success' => true,
            'data' => [
                'clients' => $formatted,
                'pagination' => [
                    'current_page' => $clients->currentPage(),
                    'last_page'    => $clients->lastPage(),
                    'per_page'     => $clients->perPage(),
                    'total'        => $clients->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $client = Client::with(['reservations.comercial', 'notes', 'callOutcomes.comercial'])
            ->find($id);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'client' => $this->formatClient($client, true),
            ],
        ]);
    }

    public function blacklist(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:5000',
        ]);

        $user = $request->user();
        $client = Client::findOrFail($id);

        return DB::transaction(function () use ($client, $user, $validated) {
            $this->workflow->apply($client, null, 'BLACKLIST', $validated, $user);

            $client->update([
                'status' => 'BLACKLISTED',
                'is_blacklisted' => true,
                'returned_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client mis en liste noire.',
            ]);
        });
    }

    protected function formatClient(Client $client, bool $detailed = false): array
    {
        $name = $client->name ?? '—';
        $enterpriseName = $client->enterprise_name ?? '—';

        $base = [
            'id' => $client->id,
            'name' => $name,
            'email' => $client->email,
            'phone' => $client->phone,
            'status' => $client->status,
            // Statut affiché (règle §2 : RESERVED qualifié par sa dernière
            // réservation) + retour éventuel du blocage temporaire.
            'display_status' => $client->displayStatus(),
            'is_blacklisted' => $client->is_blacklisted,
            'returned_at' => $client->returned_at,
            'municipality' => $client->municipality,
            'administrative_region' => $client->administrative_region,
            'neq' => $client->neq,
            'categories' => $client->categories,
            'respondents' => $client->respondents,
            'respondent_count' => $client->respondent_count,
            'authorized_categories' => $client->authorized_categories,
            'licence_number' => $client->licence_number,
            'licence_propre_numero' => $client->licence_propre_numero,
            'licence_status' => $client->licence_status,
            'licence_end_date' => $client->licence_end_date,
            'enterprise_name' => $enterpriseName,
            'created_at' => $client->created_at,
            'updated_at' => $client->updated_at,
        ];

        if ($detailed) {
            $activeReservation = $client->reservations
                ->sortByDesc('created_at')
                ->first();

            // Réservation du connecté, seulement si elle est encore active :
            // c'est elle qui conditionne l'affichage du bouton « Suite appel ».
            $myReservation = $client->reservations
                ->where('comercial_id', auth()->id())
                ->sortByDesc('created_at')
                ->first();

            if ($myReservation
                && ! in_array($myReservation->status, Reservation::ACTIVE_STATUSES, true)) {
                $myReservation = null;
            }

            if ($myReservation
                && ! in_array($client->status, ['RESERVED', 'SUCCESS'], true)) {
                $myReservation = null;
            }

            $assignedCommercial = $activeReservation?->comercial;

            $base = array_merge($base, [
                'neq' => $client->neq,
                'full_address' => $client->full_address,
                'categories' => $client->categories,
                'licence_propre' => $client->licence_propre,
                'intervenant_name' => $client->intervenant_name,
                'licence_start_date' => $client->licence_start_date,
                'respondent_count' => $client->respondent_count,
                'respondents' => $client->respondents,
                'sub_category_count' => $client->sub_category_count,
                'authorized_categories' => $client->authorized_categories,
                'surety_company' => $client->surety_company,
                'cautionnement_compagnie' => $client->cautionnement_compagnie,
                'surety_amount' => $client->surety_amount,
                'representative_name' => $client->representative_name,
                'assigned_comercial' => $assignedCommercial ? [
                    'id' => $assignedCommercial->id,
                    'email' => $assignedCommercial->email,
                    'first_name' => $assignedCommercial->first_name,
                    'last_name' => $assignedCommercial->last_name,
                ] : null,
                'reservations_count' => $client->reservations_count ?? $client->reservations()->count(),
                'notes_count' => $client->notes_count ?? $client->notes()->count(),
                'returned_at' => $client->returned_at,
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
                'my_reservation' => $myReservation ? [
                    'id' => $myReservation->id,
                    'status' => $myReservation->status,
                    'bv_count' => $myReservation->bv_count,
                    'injoinable_count' => $myReservation->injoinable_count,
                    'recall_at' => $myReservation->recall_at,
                    'rappel_after' => $myReservation->rappel_after,
                    'rappel_type' => $myReservation->rappel_type,
                ] : null,
            ]);
        }

        return $base;
    }
}
