<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallOutcome;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\User;
use App\Support\AccountVerificationLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function creerEntreprise(Request $request, AccountVerificationLinks $verificationLinks): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'mot_de_passe' => 'nullable|string|min:8|confirmed',
            'nom' => 'required|string|max:255',
            'numero_fiscal' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:255',
            'adresse' => 'nullable|string',
            'logo' => 'nullable|string|max:255',
        ]);

        $userData = [
            'email' => $request->email,
            'role' => 'ENTREPRISE',
        ];

        if ($request->filled('mot_de_passe')) {
            $userData['password_hash'] = Hash::make($request->mot_de_passe);
            $userData['email_verified_at'] = now();
        }

        $user = User::create($userData);

        $enterprise = Enterprise::create([
            'user_id' => $user->id,
            'name' => $request->nom,
            'tax_number' => $request->numero_fiscal,
            'phone' => $request->telephone,
            'address' => $request->adresse,
            'logo' => $request->logo,
        ]);

        if (!$request->filled('mot_de_passe')) {
            $verificationLinks->send($user, $request->nom, $request);
        }

        return response()->json([
            'message' => 'Entreprise créée avec succès.',
            'utilisateur' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'entreprise' => $enterprise,
        ], 201);
    }

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
