<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Api\V1\Shared\Concerns\ConfiguresRingCentralEnterprise;
use App\Http\Controllers\Controller;
use App\Services\RingCentralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CallLogController extends Controller
{
    use ConfiguresRingCentralEnterprise;

    protected RingCentralService $ringCentral;

    public function __construct(RingCentralService $ringCentral)
    {
        $this->ringCentral = $ringCentral;
    }

    /**
     * 1. Get all users / extensions — `GET /account/~/extension`
     *    (`type=User&status=Enabled&perPage=`).
     *
     * Compte RingCentral : **champs en base** de l'entreprise (trait), le
     * `.env` n'intervient que si aucune entreprise n'est résoluble.
     */
    public function users(Request $request): JsonResponse
    {
        // Avant le `try` : un `ValidationException` (entreprise sans
        // identifiants) doit rester un 422, pas un 502 « service injoignable ».
        $this->configureRingCentral($request, $this->ringCentral);

        try {
            $perPage = max(1, min(250, (int) $request->query('per_page', 100)));
            $users = $this->ringCentral->getAllUsers($perPage);

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (Throwable $e) {
            Log::error('Erreur RingCentral getAllUsers: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur de communication avec le service de téléphonie : '.$e->getMessage(),
            ], 502);
        }
    }

    /**
     * 2. Get call history by user / extension
     */
    public function userCalls(Request $request, string $extensionId): JsonResponse
    {
        $this->configureRingCentral($request, $this->ringCentral);

        try {
            $filters = $request->only(['dateFrom', 'dateTo', 'direction', 'type', 'view', 'perPage', 'page']);
            $calls = $this->ringCentral->getCallHistoryByUser($extensionId, $filters);

            return response()->json([
                'success' => true,
                'data' => $calls,
            ]);
        } catch (Throwable $e) {
            Log::error("Erreur RingCentral getCallHistoryByUser ($extensionId): ".$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Erreur de communication avec le service de téléphonie : '.$e->getMessage(),
            ], 502);
        }
    }

    /**
     * 3. Get call history by "To" phone number
     */
    public function callsToNumber(Request $request, string $phone): JsonResponse
    {
        $this->configureRingCentral($request, $this->ringCentral);

        try {
            $extensionId = (string) $request->query('extensionId', '~');
            $calls = $this->ringCentral->getCallHistoryToNumber($phone, $extensionId);

            return response()->json([
                'success' => true,
                'data' => $calls,
            ]);
        } catch (Throwable $e) {
            Log::error("Erreur RingCentral getCallHistoryToNumber ($phone): ".$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Erreur de communication avec le service de téléphonie : '.$e->getMessage(),
            ], 502);
        }
    }
}
