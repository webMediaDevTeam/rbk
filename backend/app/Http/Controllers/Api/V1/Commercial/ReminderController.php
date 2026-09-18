<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $reservations = Reservation::where('comercial_id', $user->id)
            ->whereNotNull('recall_at')
            ->whereHas('client', fn ($q) => $q->whereIn('status', ['VOICEMAIL', 'INJOINABLE']))
            ->with('client:id,rbq_data,status,phone,email,municipality')
            ->orderBy('recall_at', 'asc')
            ->get();

        $formatted = $reservations->map(fn ($r) => [
            'id' => $r->id,
            'client_id' => $r->client_id,
            'client_name' => $r->client->rbq_data['name'] ?? '—',
            'client_phone' => $r->client->phone,
            'client_municipality' => $r->client->municipality,
            'recall_after' => $r->rappel_after,
            'recall_unit' => $r->rappel_type,
            'recall_at' => $r->recall_at,
            'is_due' => $r->recall_at->lte(now()),
        ]);

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $count = Reservation::where('comercial_id', $request->user()->id)
            ->whereNotNull('recall_at')
            ->where('recall_at', '<=', now())
            ->whereHas('client', fn ($q) => $q->whereIn('status', ['VOICEMAIL', 'INJOINABLE']))
            ->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }
}
