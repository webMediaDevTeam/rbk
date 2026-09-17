<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Return all clients (not scoped to the requesting comercial/enterprise)
        $query = Client::with(['enterprise']);

        // Allow filtering by a single category id (sent as `category_id`)
        if ($categoryId = $request->input('category_id')) {
            // match any client who has this category id in the JSON `categories_id` column
            $query->whereJsonContains('categories_id', $categoryId);
        }

        // Search by client fields
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

        // Enforce only non-blacklisted, available clients for commercial listing
        $query->where('is_blacklisted', false)
              ->where('status', 'AVAILABLE');

        // Sorting
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

    /**
     * Return clients currently reserved by the authenticated comercial.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = \Illuminate\Support\Carbon::now();

        $query = Client::with(['enterprise'])
            ->whereHas('reservations', function ($q) use ($user, $now) {
                $q->where('comercial_id', $user->id)
                  ->where('expires_at', '>', $now);
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
        $user = $request->user();

        $client = Client::with(['enterprise', 'assignedComercial', 'reservations', 'notes'])
            ->where('assigned_comercial_id', $user->id)
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

    protected function formatClient(Client $client, bool $detailed = false): array
    {
        $name = $client->rbq_data['name'] ?? '—';
        $enterpriseName = $client->enterprise?->name ?? '—';

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
                'assigned_comercial_id' => $client->assigned_comercial_id,
                'enterprise_id' => $client->enterprise_id,
                'reservations_count' => $client->reservations_count ?? $client->reservations()->count(),
                'notes_count' => $client->notes_count ?? $client->notes()->count(),
            ]);
        }

        return $base;
    }
}