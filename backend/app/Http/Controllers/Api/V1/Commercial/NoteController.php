<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'interactions d'un client (`notes`) :
 *
 *   GET    /clients/{clientId}/notes  — tout le journal du client
 *   POST   /notes                     — commentaire libre (type NOTE)
 *   DELETE /notes/{id}                — commentaire, auteur ou admin
 *
 * Seul le type `NOTE` est créable/ supprimable par l'API : les événements
 * (RESERVED, YES, NO, BV, CALL_BACK, BLACKLISTED, RETURNED_TO_AVAILABLE) sont
 * écrits par le workflow et les crons — les effacer casserait l'historique,
 * les KPI « traité » et l'auto-blacklist.
 */
class NoteController extends Controller
{
    public function index(Request $request, string $clientId): JsonResponse
    {
        $notes = Note::where('client_id', $clientId)
            ->with('sender:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|uuid|exists:clients,id',
            // Les événements du workflow ne se créent pas par HTTP.
            'type' => 'sometimes|nullable|in:' . Note::TYPE_NOTE,
            'description' => 'required|string',
        ]);

        $note = Note::create([
            'client_id' => $validated['client_id'],
            'sender_id' => $request->user()->id,
            'type' => $validated['type'] ?? Note::TYPE_NOTE,
            'description' => $validated['description'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Note créée avec succès.',
            'data' => $note->load('sender:id,first_name,last_name,email'),
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $note = Note::findOrFail($id);
        $user = $request->user();

        // Événement du workflow : immuable.
        if ($note->type !== Note::TYPE_NOTE) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les commentaires peuvent être supprimés.',
            ], 422);
        }

        $isOwner = (string) $note->sender_id === (string) $user->id;
        $isAdmin = in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true);

        if (! $isOwner && ! $isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez supprimer que vos propres commentaires.',
            ], 403);
        }

        $note->delete();

        return response()->json([
            'success' => true,
            'message' => 'Note supprimée.',
        ]);
    }
}
