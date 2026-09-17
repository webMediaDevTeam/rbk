<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ReservationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'count' => 'required|integer|min:1|max:1000',
            'quick' => 'nullable|string',
            'amount' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|in:HEURE,JOUR,SEMAINE,MOIS',
        ]);

        $count = (int) $request->input('count', 1);

        // Determine expires_at
        $now = Carbon::now();
        $expires = null;

        if ($quick = $request->input('quick')) {
            // quick like '1j', '2j', '1w'
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

        $reserved = 0;
        $conflicts = [];

        // Candidate clients: not blacklisted and without active reservations
        $candidatesQuery = Client::where('is_blacklisted', false)
            ->whereDoesntHave('reservations', fn($q) => $q->where('expires_at', '>', $now))
            ->orderBy('created_at');

        $candidates = $candidatesQuery->limit($count * 3)->get();

        foreach ($candidates as $client) {
            if ($reserved >= $count) break;

            DB::beginTransaction();
            try {
                // lock the client row
                $c = Client::where('id', $client->id)->lockForUpdate()->first();

                $active = $c->reservations()->where('expires_at', '>', $now)->first();
                if ($active) {
                    // conflict
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

                // create reservation
                $reservation = Reservation::create([
                    'client_id' => $c->id,
                    'comercial_id' => $request->user()->id,
                    'status' => 'RESERVED',
                    'expires_at' => $expires,
                ]);

                $reserved++;
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                // treat as conflict
                $conflicts[] = [
                    'client_id' => $client->id,
                    'name' => $client->rbq_data['name'] ?? null,
                    'status' => $client->status ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'requested' => $count,
                'reserved' => $reserved,
                'conflicts' => $conflicts,
            ],
        ]);
    }
}
