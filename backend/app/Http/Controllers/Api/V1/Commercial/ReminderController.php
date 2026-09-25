<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rappels du commercial connecté, séparés par type :
 *
 *  - page « Rappels »      -> INJOINABLE seuls (rappel choisi par l'employé) ;
 *  - page « Auto-rappels » -> BV seuls (rappel automatique à 3 jours).
 */
class ReminderController extends Controller
{
    /** Type renvoyé quand `type` n'est pas fourni : la page « Rappels » (INJOINABLE). */
    private const DEFAULT_TYPE = 'INJOINABLE';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $reservations = Reservation::where('comercial_id', $user->id)
            ->whereNotNull('recall_at')
            ->where('status', $this->recallType($request))
            ->with('client:id,name,enterprise_name,status,phone,email,municipality')
            ->orderBy('recall_at', 'asc')
            ->get();

        $formatted = $reservations->map(fn ($r) => [
            'id' => $r->id,
            'client_id' => $r->client_id,
            'client_name' => $r->client->name ?? '—',
            'client_phone' => $r->client->phone,
            'client_municipality' => $r->client->municipality,
            'status' => $r->status,
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
            ->where('status', $this->recallType($request))
            ->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Filtre de type : `?type=BV` (auto-rappels) ou `?type=INJOINABLE`
     * (défaut, page « Rappels »).
     */
    private function recallType(Request $request): string
    {
        $validated = $request->validate([
            'type' => 'sometimes|nullable|in:BV,INJOINABLE',
        ]);

        return $validated['type'] ?? self::DEFAULT_TYPE;
    }
}
