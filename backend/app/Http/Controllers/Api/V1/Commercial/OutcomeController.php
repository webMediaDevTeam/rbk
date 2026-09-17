<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\CallOutcome;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class OutcomeController extends Controller
{
    public function store(Request $request, string $clientId): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => 'required|in:OUI,NON,BOITE_VOCALE,BLACKLIST',
            'note' => 'nullable|string|max:5000',
            'recall_amount' => 'nullable|integer|min:1',
            'recall_unit' => 'nullable|string|in:MINUTE,HEURE,JOUR,SEMAINE,MOIS',
        ]);

        $user = $request->user();
        $client = Client::findOrFail($clientId);

        $hasReservation = $client->reservations()
            ->where('comercial_id', $user->id)
            ->exists();

        $hasCall = $client->callOutcomes()
            ->where('comercial_id', $user->id)
            ->exists();

        $outcome = $validated['outcome'];

        if (in_array($outcome, ['OUI', 'BOITE_VOCALE']) && !$hasReservation) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez réserver ce client avant de changer son statut.',
            ], 422);
        }

        if ($outcome === 'BLACKLIST' && !($hasReservation && $hasCall)) {
            return response()->json([
                'success' => false,
                'message' => 'La mise en liste noire nécessite une réservation active et un appel précédent.',
            ], 422);
        }

        if (!in_array($outcome, ['OUI', 'BOITE_VOCALE', 'BLACKLIST']) && !$hasReservation && !$hasCall) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez avoir une réservation ou un historique d\'appel pour ce client.',
            ], 403);
        }

        return DB::transaction(function () use ($request, $validated, $client, $user) {
            $outcome = $validated['outcome'];

            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $user->id,
                'outcome' => $outcome,
                'note' => $validated['note'] ?? null,
                'recall_amount' => $validated['recall_amount'] ?? null,
                'recall_unit' => $validated['recall_unit'] ?? null,
            ]);

            $reservation = $client->reservations()
                ->where('comercial_id', $user->id)
                ->latest('created_at')
                ->first();

            match ($outcome) {
                'OUI' => $this->handleOui($client, $reservation),
                'NON' => $this->handleNon($client, $reservation),
                'BOITE_VOCALE' => $this->handleBoiteVocale($client, $reservation, $validated),
                'BLACKLIST' => $this->handleBlacklist($client, $reservation),
            };

            return response()->json([
                'success' => true,
                'message' => match ($outcome) {
                    'OUI' => 'Client confirmé. Réservation maintenue.',
                    'NON' => 'Client refusé. Indisponible pour 3 mois.',
                    'BOITE_VOCALE' => 'Rappel configuré.',
                    'BLACKLIST' => 'Client mis en liste noire.',
                },
                'data' => [
                    'client_status' => $client->fresh()->status,
                    'is_blacklisted' => $client->fresh()->is_blacklisted,
                ],
            ]);
        });
    }

    public function release(Request $request, string $clientId): JsonResponse
    {
        $user = $request->user();
        $client = Client::findOrFail($clientId);

        $reservation = $client->reservations()
            ->where('comercial_id', $user->id)
            ->latest('created_at')
            ->first();

        if (!$reservation) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune réservation active trouvée pour ce client.',
            ], 404);
        }

        return DB::transaction(function () use ($client, $reservation, $user) {
            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $user->id,
                'outcome' => 'OUI',
                'note' => 'Client rendu disponible.',
            ]);

            $reservation->delete();

            $client->update([
                'status' => 'AVAILABLE',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client rendu disponible.',
            ]);
        });
    }

    private function handleOui(Client $client, ?Reservation $reservation): void
    {
        $client->update(['status' => 'RESERVED']);

        if ($reservation) {
            $reservation->update(['expires_at' => Carbon::now()->addYear()]);
        }
    }

    private function handleNon(Client $client, ?Reservation $reservation): void
    {
        $client->update([
            'status' => 'AVAILABLE',
            'blocked_until' => Carbon::now()->addMonths(3),
        ]);

        if ($reservation) {
            $reservation->delete();
        }

        $this->checkAutoBlacklist($client);
    }

    private function handleBoiteVocale(Client $client, ?Reservation $reservation, array $validated): void
    {
        $amount = $validated['recall_amount'] ?? 1;
        $unit = $validated['recall_unit'] ?? 'JOUR';

        $recallAt = match ($unit) {
            'MINUTE' => Carbon::now()->addMinutes($amount),
            'HEURE' => Carbon::now()->addHours($amount),
            'JOUR' => Carbon::now()->addDays($amount),
            'SEMAINE' => Carbon::now()->addWeeks($amount),
            'MOIS' => Carbon::now()->addMonths($amount),
        };

        $client->update(['status' => 'VOICEMAIL']);

        if ($reservation) {
            $reservation->update([
                'rappel_after' => $amount,
                'rappel_type' => $unit,
                'recall_at' => $recallAt,
                'expires_at' => Carbon::now()->addMonth(),
            ]);
        }
    }

    private function handleBlacklist(Client $client, ?Reservation $reservation): void
    {
        $client->update([
            'status' => 'BLACKLISTED',
            'is_blacklisted' => true,
            'blocked_until' => null,
        ]);

        $client->reservations()->delete();
    }

    private function checkAutoBlacklist(Client $client): void
    {
        $nonCount = CallOutcome::where('client_id', $client->id)
            ->where('outcome', 'NON')
            ->distinct('comercial_id')
            ->count();

        $activeCommercials = User::where('role', 'COMERCIAL')
            ->where('status', 'ACTIVE')
            ->count();

        if ($nonCount >= $activeCommercials && $activeCommercials > 0) {
            $client->update([
                'is_blacklisted' => true,
                'status' => 'BLACKLISTED',
            ]);
        }
    }
}
