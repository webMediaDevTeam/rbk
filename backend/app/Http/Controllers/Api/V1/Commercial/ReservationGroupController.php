<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->input('per_page', 20), 300);

        // Tableau « Mes listes » : compteurs par statut + employé, calculés
        // automatiquement côté serveur (jointures), jamais à la main côté client.
        $groups = ReservationGroup::query()
            ->with(['comercial' => fn ($q) => $q->select(['id', 'first_name', 'last_name', 'email'])])
            ->withCount([
                'reservations as clients_count',
                'reservations as traites_count' => fn ($q) => $q
                    ->whereIn('status', ['OUI', 'NON', 'BV', 'INJOINABLE']),
                'reservations as oui_count' => fn ($q) => $q->where('status', 'OUI'),
                'reservations as non_count' => fn ($q) => $q->where('status', 'NON'),
                'reservations as bv_count' => fn ($q) => $q->where('status', 'BV'),
                'reservations as injoinable_count' => fn ($q) => $q->where('status', 'INJOINABLE'),
                // Restant : pas encore appelés (EN_ATTENT).
                'reservations as restant_count' => fn ($q) => $q->where('status', 'EN_ATTENT'),
            ])
            ->where('comercial_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = $groups->getCollection()->map(function (ReservationGroup $group) {
            $data = $group->toArray();
            $owner = $group->comercial;
            $fullName = $owner ? trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')) : '';
            $data['employe'] = $fullName !== '' ? $fullName : ($owner?->email ?? null);

            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'groups' => $items,
                'pagination' => [
                    'current_page' => $groups->currentPage(),
                    'last_page'    => $groups->lastPage(),
                    'per_page'     => $groups->perPage(),
                    'total'        => $groups->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $group = ReservationGroup::where('id', $id)
            ->where('comercial_id', $user->id)
            ->with(['reservations' => function ($q) {
                $q->with('client')->latest('created_at');
            }])
            // Compteurs traités / restant calculés en SQL, comme pour le
            // tableau « Mes listes » (index) — jamais côté client.
            ->withCount([
                'reservations as clients_count',
                'reservations as traites_count' => fn ($q) => $q
                    ->whereIn('status', ['OUI', 'NON', 'BV', 'INJOINABLE']),
                'reservations as restant_count' => fn ($q) => $q->where('status', 'EN_ATTENT'),
                'reservations as oui_count' => fn ($q) => $q->where('status', 'OUI'),
                'reservations as non_count' => fn ($q) => $q->where('status', 'NON'),
                'reservations as bv_count' => fn ($q) => $q->where('status', 'BV'),
                'reservations as injoinable_count' => fn ($q) => $q->where('status', 'INJOINABLE'),
            ])
            ->first();

        if (! $group) {
            return response()->json(['success' => false, 'message' => 'Groupe introuvable.'], 404);
        }

        // Statut affiché : dernière réservation des clients de la liste
        // préchargée en une seule requête.
        Client::loadLatestReservations($group->reservations->pluck('client')->filter());

        $reservations = $group->reservations->map(fn ($r) => [
            'id' => $r->id,
            'status' => $r->status,
            'bv_count' => $r->bv_count,
            'injoinable_count' => $r->injoinable_count,
            'recall_at' => $r->recall_at,
            'created_at' => $r->created_at,
            'client' => $r->client ? [
                'id' => $r->client->id,
                'name' => $r->client->name ?? null,
                'phone' => $r->client->phone,
                'email' => $r->client->email,
                'municipality' => $r->client->municipality,
                'status' => $r->client->status,
                'display_status' => $r->client->displayStatus(),
                'is_blacklisted' => $r->client->is_blacklisted,
                'returned_at' => $r->client->returned_at,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'total' => $group->total,
                    'reserved_count' => $group->reserved_count,
                    'clients_count' => (int) $group->clients_count,
                    'traites_count' => (int) $group->traites_count,
                    'restant_count' => (int) $group->restant_count,
                    'oui_count' => (int) $group->oui_count,
                    'non_count' => (int) $group->non_count,
                    'bv_count' => (int) $group->bv_count,
                    'injoinable_count' => (int) $group->injoinable_count,
                    'created_at' => $group->created_at,
                ],
                'reservations' => $reservations,
            ],
        ]);
    }

    /**
     * Renommer un groupe : propriétaire du groupe ou Admin / Super Admin.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $group = ReservationGroup::where('id', $id)->first();

        if (! $group) {
            return response()->json(['success' => false, 'message' => 'Groupe introuvable.'], 404);
        }

        $isOwner = $group->comercial_id === $user->id;
        $isAdmin = in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true);

        if (! $isOwner && ! $isAdmin) {
            return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        DB::table('reservation_groups')->where('id', $group->id)->update(['name' => $validated['name']]);

        return response()->json([
            'success' => true,
            'message' => 'Liste renommée.',
            'data' => ['id' => $group->id, 'name' => $validated['name']],
        ]);
    }
}
