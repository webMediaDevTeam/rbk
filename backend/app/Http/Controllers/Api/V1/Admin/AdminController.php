<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Models\User;
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
     * `notes` (sender = l'admin, description = qui a déblocké) — la liste
     * noire doit rester historisable.
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
                'description' => $this->unblockDescription($request->user()),
            ]);

            return response()->json([
                'message' => 'Client débloqué avec succès.',
                'client' => $client->fresh(),
            ]);
        });
    }

    /**
     * Description de l'événement de déblocage : « Client restauré AVAILABLE
     * par <nom> ». La note est **saisie humaine** (émetteur = l'admin) :
     * 8 mots maximum, donc repli sur l'email / l'id quand le nom est absent
     * ou trop composé.
     */
    private function unblockDescription(?User $user): string
    {
        $name = trim(($user?->first_name ?? '').' '.($user?->last_name ?? ''));

        // 4 mots de préfixe + 3 mots de nom = 7 <= 8.
        if ($name === '' || substr_count($name, ' ') > 2) {
            $name = $user?->email ?: (string) $user?->id;
        }

        return sprintf('Client restauré AVAILABLE par %s.', $name);
    }
}
