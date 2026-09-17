<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Request $request, string $clientId): JsonResponse
    {
        $notes = Note::where('client_id', $clientId)
            ->with('comercial:id,first_name,last_name,email')
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
            'type' => 'required|in:CALL_LOG,TASK,GENERAL_NOTE',
            'content' => 'required|string',
            'due_date' => 'nullable|date',
            'call_duration_seconds' => 'nullable|integer|min:0',
        ]);

        $validated['comercial_id'] = $request->user()->id;

        $note = Note::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Note créée avec succès.',
            'data' => $note->load('comercial:id,first_name,last_name,email'),
        ], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $note = Note::findOrFail($id);
        $note->delete();

        return response()->json([
            'success' => true,
            'message' => 'Note supprimée.',
        ]);
    }
}
