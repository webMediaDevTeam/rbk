<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
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
        $perPage = min((int) $request->input('per_page', 20), 100);

        $groups = ReservationGroup::withCount('reservations')
            ->where('comercial_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $groups->getCollection()->each(function ($group) {
            $group->pending_count = \App\Models\Reservation::pendingFor($group->comercial_id, $group->id)->count();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'groups' => $groups->getCollection(),
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
            ->first();

        if (! $group) {
            return response()->json(['success' => false, 'message' => 'Groupe introuvable.'], 404);
        }

        $reservations = $group->reservations->map(fn ($r) => [
            'id' => $r->id,
            'status' => $r->status,
            'created_at' => $r->created_at,
            'expires_at' => $r->expires_at,
            'client' => $r->client ? [
                'id' => $r->client->id,
                'name' => $r->client->rbq_data['name'] ?? null,
                'phone' => $r->client->phone,
                'email' => $r->client->email,
                'municipality' => $r->client->municipality,
                'status' => $r->client->status,
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
                    'created_at' => $group->created_at,
                ],
                'reservations' => $reservations,
            ],
        ]);
    }

    public function releasePending(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $group = ReservationGroup::where('id', $id)
            ->where('comercial_id', $user->id)
            ->first();

        if (! $group) {
            return response()->json(['success' => false, 'message' => 'Groupe introuvable.'], 404);
        }

        return DB::transaction(function () use ($user, $group) {
            $reservations = Reservation::pendingFor($user->id, $group->id)->get();

            $released = 0;
            foreach ($reservations as $reservation) {
                $client = $reservation->client;
                $reservation->delete();
                $client->update(['status' => 'AVAILABLE']);
                $released++;
            }

            return response()->json([
                'success' => true,
                'message' => "$released prospect(s) retourné(s) à disponible.",
                'data' => ['released' => $released],
            ]);
        });
    }
}