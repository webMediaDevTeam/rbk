<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function listeNoire(Request $request): JsonResponse
    {
        $clients = Client::where('is_blacklisted', true)->get();

        return response()->json([
            'clients' => $clients,
        ]);
    }

    /**
     * Déblocage admin : le client redevient AVAILABLE et ses réservations sont
     * supprimées. L'événement `RETURNED_TO_AVAILABLE` est journalisé dans
     * `notes` (sender = l'admin) — la liste noire doit rester historisable.
     */
    public function debloquerClient(Request $request, string $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        return DB::transaction(function () use ($client, $request) {
            $client->update([
                'status' => Client::STATUS_AVAILABLE,
                'is_blacklisted' => false,
                'returned_at' => null,
            ]);

            // Retire d'abord les rappels, puis vide les réservations.
            $client->rappels()->delete();
            $client->reservations()->delete();

            Note::create([
                'client_id' => $client->id,
                'sender_id' => $request->user()->id,
                'type' => Note::TYPE_RETURNED_TO_AVAILABLE,
                'description' => 'Client débloqué par un administrateur.',
            ]);

            return response()->json([
                'message' => 'Client débloqué avec succès.',
                'client' => $client->fresh(),
            ]);
        });
    }
}
