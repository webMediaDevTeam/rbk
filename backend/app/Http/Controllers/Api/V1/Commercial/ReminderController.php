<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Rappel;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rappels de l'employé connecté, séparés par type de réservation :
 *
 *  - page « Rappels »      -> CALL_BACK seuls (rappel choisi par l'employé) ;
 *  - page « Auto-rappels » -> BV_VOICEMAIL seuls (rappel automatique à 3 jours).
 *
 * Source : table `rappels` (reminder_date), plus les colonnes de rappel de
 * `reservations` qui ont été supprimées.
 */
class ReminderController extends Controller
{
    /** Type renvoyé quand `type` est absent : la page « Rappels » (CALL_BACK). */
    private const DEFAULT_TYPE = Reservation::STATUS_CALL_BACK;

    public function index(Request $request): JsonResponse
    {
        $rappels = $this->query($request)
            ->with([
                'client:id,name,enterprise_name,status,phone,email,municipality',
                'reservation:id,status',
            ])
            ->orderBy('reminder_date', 'asc')
            ->get();

        $formatted = $rappels->map(function (Rappel $rappel) {
            [$amount, $unit] = $rappel->delay();

            return [
                'id' => $rappel->id,
                'client_id' => $rappel->client_id,
                'client_name' => $rappel->client->name ?? '—',
                'client_phone' => $rappel->client->phone,
                'client_municipality' => $rappel->client->municipality,
                'status' => $rappel->reservation?->status,
                'recall_after' => $amount,
                'recall_unit' => $unit,
                'recall_at' => $rappel->reminder_date,
                'is_due' => $rappel->isDue(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $count = $this->query($request)
            ->where('reminder_date', '<=', now())
            ->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Rappels de l'employé connecté, filtrés par type de réservation.
     */
    private function query(Request $request)
    {
        $type = $this->recallType($request);

        return Rappel::where('comercial_id', $request->user()->id)
            ->whereHas('reservation', fn ($q) => $q->where('status', $type));
    }

    /**
     * Filtre de type : `?type=BV` (auto-rappels) ou `?type=CALL_BACK`
     * (défaut, page « Rappels »).
     */
    private function recallType(Request $request): string
    {
        $validated = $request->validate([
            // BV = alias court de BV_VOICEMAIL (ancienne valeur d'URL).
            'type' => 'sometimes|nullable|in:BV,' . Reservation::STATUS_BV_VOICEMAIL . ',' . Reservation::STATUS_CALL_BACK,
        ]);

        $type = $validated['type'] ?? self::DEFAULT_TYPE;

        return $type === 'BV' ? Reservation::STATUS_BV_VOICEMAIL : $type;
    }
}
