<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ReservationController extends Controller
{
    public function pendingCount(Request $request): JsonResponse
    {
        $count = Reservation::pendingFor($request->user()->id)->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }

    public function releasePending(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $reservations = Reservation::pendingFor($request->user()->id)->get();

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

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'group_name' => 'required|string|max:255',
            'count' => 'required|integer|min:1|max:1000',
            'quick' => 'nullable|string',
            'amount' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|in:HEURE,JOUR,SEMAINE,MOIS',
        ]);

        $count = (int) $request->input('count', 1);
        $groupName = $request->input('group_name');

        $now = Carbon::now();
        $expires = null;

        if ($quick = $request->input('quick')) {
            if (preg_match('/^(\d+)([hjw])$/i', $quick, $m)) {
                $num = (int) $m[1];
                $unit = strtolower($m[2]);
                if ($unit === 'h') $expires = $now->copy()->addHours($num);
                if ($unit === 'j') $expires = $now->copy()->addDays($num);
                if ($unit === 'w') $expires = $now->copy()->addWeeks($num);
            }
        } elseif ($request->filled('amount') && $request->filled('unit')) {
            $amt = (int) $request->input('amount');
            $unit = $request->input('unit');
            match ($unit) {
                'HEURE' => $expires = $now->copy()->addHours($amt),
                'JOUR' => $expires = $now->copy()->addDays($amt),
                'SEMAINE' => $expires = $now->copy()->addWeeks($amt),
                'MOIS' => $expires = $now->copy()->addMonths($amt),
                default => $expires = null,
            };
        }

        if (! $expires) {
            return response()->json(['success' => false, 'message' => 'Invalid duration provided.'], 422);
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

        $candidatesQuery = Client::where('status', 'AVAILABLE')
            ->where('is_blacklisted', false)
            ->where(fn($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '<', $now))
            ->whereDoesntHave('reservations')
            ->whereDoesntHave('callOutcomes', fn($q) => $q
                ->where('comercial_id', $user->id)
                ->whereIn('outcome', ['NON', 'BOITE_VOCALE']))
            ->orderBy('created_at');

        $candidates = $candidatesQuery->limit($count * 3)->get();

        foreach ($candidates as $client) {
            if ($reserved >= $count) break;

            DB::beginTransaction();
            try {
                $c = Client::where('id', $client->id)->lockForUpdate()->first();

                $active = $c->reservations()->latest('created_at')->first();
                if ($active) {
                    $conflicts[] = [
                        'client_id' => $c->id,
                        'name' => $c->rbq_data['name'] ?? null,
                        'status' => $c->status,
                        'reserved_by' => $active->comercial?->name ?? null,
                        'expires_at' => $active->expires_at,
                    ];
                    DB::commit();
                    continue;
                }

                Reservation::create([
                    'client_id' => $c->id,
                    'comercial_id' => $user->id,
                    'reservation_group_id' => $group->id,
                    'status' => 'RESERVED',
                    'expires_at' => $expires,
                ]);

                $c->update(['status' => 'RESERVED']);

                $reserved++;
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $conflicts[] = [
                    'client_id' => $client->id,
                    'name' => $client->rbq_data['name'] ?? null,
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