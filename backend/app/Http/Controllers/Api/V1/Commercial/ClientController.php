<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\CallOutcome;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::query();

        if ($categoryId = $request->input('category_id')) {
            $query->whereJsonContains('categories_id', $categoryId);
        }

        if ($search = $request->input('search')) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('rbq_data->name', 'LIKE', $like)
                  ->orWhere('email', 'LIKE', $like)
                  ->orWhere('phone', 'LIKE', $like)
                  ->orWhere('neq', 'LIKE', $like)
                  ->orWhere('municipality', 'LIKE', $like)
                  ->orWhere('licence_number', 'LIKE', $like);
            });
        }

        $query->where('is_blacklisted', false)
              ->where('status', 'AVAILABLE')
              ->whereDoesntHave('reservations');

        $sortable = [
            'name'        => 'rbq_data->name',
            'email'       => 'email',
            'phone'       => 'phone',
            'status'      => 'status',
            'municipality' => 'municipality',
            'created_at'  => 'created_at',
            'licence_end_date' => 'licence_end_date',
        ];

        $sortBy    = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (array_key_exists($sortBy, $sortable)) {
            $query->orderBy($sortable[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $clients = $query->paginate($perPage);

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

        $perPage = min((int) $request->input('per_page', 20), 100);
        $clients = $query->paginate($perPage);

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
            'note' => 'required|string|max:5000',
        ]);

        $user = $request->user();
        $client = Client::findOrFail($id);

        return DB::transaction(function () use ($client, $user, $validated) {
            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $user->id,
                'outcome' => 'BLACKLIST',
                'note' => $validated['note'],
            ]);

            $client->update([
                'status' => 'BLACKLISTED',
                'is_blacklisted' => true,
                'blocked_until' => null,
            ]);

            $client->reservations()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Client mis en liste noire.',
            ]);
        });
    }

    protected function formatClient(Client $client, bool $detailed = false): array
    {
        $name = $client->rbq_data['name'] ?? '—';
        $enterpriseName = $client->rbq_data['entreprise_name'] ?? '—';

        $base = [
            'id' => $client->id,
            'name' => $name,
            'email' => $client->email,
            'phone' => $client->phone,
            'status' => $client->status,
            'is_blacklisted' => $client->is_blacklisted,
            'municipality' => $client->municipality,
            'administrative_region' => $client->administrative_region,
            'licence_number' => $client->licence_number,
            'licence_status' => $client->licence_status,
            'licence_end_date' => $client->licence_end_date,
            'enterprise_name' => $enterpriseName,
            'created_at' => $client->created_at,
        ];

        if ($detailed) {
            $activeReservation = $client->reservations
                ->sortByDesc('created_at')
                ->first();

            $myReservation = $client->reservations
                ->where('comercial_id', auth()->id())
                ->sortByDesc('created_at')
                ->first();

            $assignedCommercial = $activeReservation?->comercial;

            $base = array_merge($base, [
                'neq' => $client->neq,
                'full_address' => $client->full_address,
                'categories' => $client->categories,
                'categories_id' => $client->categories_id,
                'rbq_data' => $client->rbq_data,
                'licence_propre' => $client->licence_propre,
                'intervenant_name' => $client->intervenant_name,
                'licence_start_date' => $client->licence_start_date,
                'respondent_count' => $client->respondent_count,
                'respondents' => $client->respondents,
                'sub_category_count' => $client->sub_category_count,
                'authorized_categories' => $client->authorized_categories,
                'surety_company' => $client->surety_company,
                'surety_amount' => $client->surety_amount,
                'representative_name' => $client->representative_name,
                'enterprise_id' => $client->rbq_data['entreprise_id'] ?? null,
                'assigned_comercial' => $assignedCommercial ? [
                    'id' => $assignedCommercial->id,
                    'email' => $assignedCommercial->email,
                    'first_name' => $assignedCommercial->first_name,
                    'last_name' => $assignedCommercial->last_name,
                ] : null,
                'reservations_count' => $client->reservations_count ?? $client->reservations()->count(),
                'notes_count' => $client->notes_count ?? $client->notes()->count(),
                'blocked_until' => $client->blocked_until,
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
                    'expires_at' => $myReservation->expires_at,
                    'rappel_after' => $myReservation->rappel_after,
                    'rappel_type' => $myReservation->rappel_type,
                ] : null,
            ]);
        }

        return $base;
    }
}
