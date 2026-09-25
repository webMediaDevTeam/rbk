<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    /**
     * Nombre de réservations actives du commercial connecté (compteur header).
     */
    public function activeCount(Request $request): JsonResponse
    {
        $count = Reservation::active()
            ->where('comercial_id', $request->user()->id)
            ->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Réserve un lot de clients. Aucune expiration : la réservation reste
     * au commercial jusqu'à clôture (unblock admin).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // Réservation sans nom : le champ n'est plus demandé à l'utilisateur.
            'group_name' => 'nullable|string|max:255',
            // Nombre de prospects : 200 / 250 / 300 uniquement, pas de nombre libre.
            'count' => 'required|integer|in:200,250,300',
        ]);

        $count = (int) $request->input('count');
        $groupName = trim((string) $request->input('group_name', ''));
        if ($groupName === '') {
            $groupName = sprintf('Liste du %s', now()->format('d/m/Y H:i'));
        }
        $user = $request->user();

        $group = ReservationGroup::create([
            'comercial_id' => $user->id,
            'name' => $groupName,
            'total' => $count,
            'reserved_count' => 0,
        ]);

        $reserved = 0;
        $conflicts = [];

        $candidates = Client::available()
            ->where('is_blacklisted', false)
            ->whereDoesntHave('reservations', fn ($q) => $q->active())
            ->whereDoesntHave('callOutcomes', fn ($q) => $q
                ->where('comercial_id', $user->id)
                ->whereIn('outcome', ['NON', 'BV']))
            ->orderBy('created_at')
            ->limit($count * 3)
            ->get();

        foreach ($candidates as $client) {
            if ($reserved >= $count) break;

            DB::beginTransaction();
            try {
                $c = Client::where('id', $client->id)->lockForUpdate()->first();

                $active = $c->reservations()->active()->latest('created_at')->first();
                if ($active) {
                    $owner = $active->comercial;
                    $conflicts[] = [
                        'client_id' => $c->id,
                        'name' => $c->name ?? null,
                        'status' => $c->status,
                        'reserved_by' => $owner
                            ? (trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')) ?: $owner->email)
                            : null,
                        'reservation_status' => $active->status,
                    ];
                    DB::commit();
                    continue;
                }

                Reservation::create([
                    'client_id' => $c->id,
                    'comercial_id' => $user->id,
                    'reservation_group_id' => $group->id,
                    'status' => 'EN_ATTENT',
                ]);

                $c->update(['status' => 'RESERVED']);

                $reserved++;
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $conflicts[] = [
                    'client_id' => $client->id,
                    'name' => $client->name ?? null,
                    'status' => $client->status ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $group->update(['reserved_count' => $reserved]);

        return response()->json([
            'success' => true,
            'data' => [
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'total' => $group->total,
                    'reserved_count' => $reserved,
                ],
                'requested' => $count,
                'reserved' => $reserved,
                'conflicts' => $conflicts,
            ],
        ]);
    }
}
