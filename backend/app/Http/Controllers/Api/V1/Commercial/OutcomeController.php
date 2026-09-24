<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Services\CallWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutcomeController extends Controller
{
    public function __construct(private CallWorkflowService $workflow)
    {
    }

    public function store(Request $request, string $clientId): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => 'required|in:OUI,NON,BV,INJOINABLE',
            'note' => 'nullable|string|max:5000',
            // INJOINABLE : rappel à la date/heure choisie par l'employé (UDAPTE.md).
            'recall_at' => 'required_if:outcome,INJOINABLE|nullable|date|after:now',
        ], [
            'recall_at.required_if' => 'La date et l\'heure du rappel sont obligatoires pour un client injoignable.',
            'recall_at.date' => 'La date de rappel n\'est pas valide.',
            'recall_at.after' => 'La date de rappel doit être dans le futur.',
        ]);

        $user = $request->user();
        $client = Client::findOrFail($clientId);
        $outcome = $validated['outcome'];

        $reservation = $client->reservations()
            ->where('comercial_id', $user->id)
            ->latest('created_at')
            ->first();

        $hasCall = $client->callOutcomes()
            ->where('comercial_id', $user->id)
            ->exists();

        if (in_array($outcome, ['OUI', 'BV', 'INJOINABLE']) && ! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez réserver ce client avant de changer son statut.',
            ], 422);
        }

        if ($outcome === 'NON' && ! $reservation && ! $hasCall) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez avoir une réservation ou un historique d\'appel pour ce client.',
            ], 403);
        }

        $result = $this->workflow->apply($client, $reservation, $outcome, $validated, $user);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'client_status' => $result['client_status'],
                'blacklisted' => $result['blacklisted'],
            ],
        ]);
    }
}
