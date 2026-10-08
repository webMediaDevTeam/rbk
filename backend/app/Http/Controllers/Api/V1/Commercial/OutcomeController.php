<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Services\CallWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutcomeController extends Controller
{
    public function __construct(private CallWorkflowService $workflow) {}

    public function store(Request $request, string $clientId): JsonResponse
    {
        $validated = $request->validate([
            // Issues d'appel du modèle : YES / NO / BV / CALL_BACK / DOUBLE / INFO.
            'outcome' => 'required|in:'.implode(',', self::outcomes()),
            'note' => 'nullable|string|max:5000',
            // CALL_BACK : rappel à la date/heure choisie par l'employé.
            'recall_at' => 'required_if:outcome,'.Note::TYPE_CALL_BACK.'|nullable|date|after:now',
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

        $hasCall = $client->notes()
            ->calls()
            ->where('sender_id', $user->id)
            ->exists();

        // Double / Info rejoignent Oui / BV / À rappeler : le prospect reste
        // tenu par l'employé (régime RESERVED), la réservation est donc
        // **obligatoire**.
        if (in_array($outcome, [
            Note::TYPE_YES,
            Note::TYPE_BV,
            Note::TYPE_CALL_BACK,
            Note::TYPE_DOUBLE,
            Note::TYPE_INFO,
        ], true) && ! $reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez réserver ce client avant de changer son statut.',
            ], 422);
        }

        if ($outcome === Note::TYPE_NO && ! $reservation && ! $hasCall) {
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

    /**
     * Issues d'appel acceptées par l'endpoint (le texte reste un champ
     * `outcome` côté HTTP, les valeurs sont celles du modèle).
     *
     * @return list<string>
     */
    private static function outcomes(): array
    {
        return [
            Note::TYPE_YES,
            Note::TYPE_NO,
            Note::TYPE_BV,
            Note::TYPE_CALL_BACK,
            Note::TYPE_DOUBLE,
            Note::TYPE_INFO,
        ];
    }
}
