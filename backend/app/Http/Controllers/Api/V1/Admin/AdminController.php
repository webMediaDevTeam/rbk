<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallOutcome;
use App\Models\Client;
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

    public function debloquerClient(Request $request, string $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        return DB::transaction(function () use ($client, $request) {
            $client->update([
                'status' => 'AVAILABLE',
                'is_blacklisted' => false,
                'blocked_until' => null,
            ]);

            $client->reservations()->delete();

            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $request->user()->id,
                'outcome' => 'UNBLACKLIST',
                'note' => 'Client débloqué par un administrateur.',
            ]);

            return response()->json([
                'message' => 'Client débloqué avec succès.',
                'client' => $client->fresh(),
            ]);
        });
    }
}
