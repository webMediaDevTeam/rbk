<?php

namespace App\Http\Controllers\Api\V1\Entreprise;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\AccountVerificationLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EnterpriseController extends Controller
{
    public function creerCommercial(Request $request, AccountVerificationLinks $verificationLinks): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'mot_de_passe' => 'nullable|string|min:8|confirmed',
            'prenom' => 'required|string|max:255',
            'nom' => 'required|string|max:255',
            'telephone' => 'nullable|string|max:255',
            'informations_supplementaires' => 'nullable|string',
            'entreprise_id' => 'nullable|uuid|exists:enterprises,id',
        ]);

        $actor = $request->user();

        // Determine which enterprise to link to
        if ($actor->role === 'ENTREPRISE') {
            $enterprise = $actor->enterprise;
            if (!$enterprise) {
                return response()->json(['message' => 'Profil entreprise non trouvé.'], 404);
            }
        } else {
            // ADMIN / SUPER_ADMIN must pass entreprise_id
            if (!$request->filled('entreprise_id')) {
                return response()->json(['message' => 'Le champ entreprise_id est requis pour votre rôle.'], 422);
            }
            $enterprise = \App\Models\Enterprise::find($request->entreprise_id);
            if (!$enterprise) {
                return response()->json(['message' => 'Entreprise introuvable.'], 404);
            }
        }

        $userData = [
            'email' => $request->email,
            'role' => 'COMERCIAL',
        ];

        if ($request->filled('mot_de_passe')) {
            $userData['password_hash'] = Hash::make($request->mot_de_passe);
            $userData['email_verified_at'] = now();
        }

        $user = User::create($userData);

        $employee = Employee::create([
            'user_id' => $user->id,
            'enterprise_id' => $enterprise->id,
            'first_name' => $request->prenom,
            'last_name' => $request->nom,
            'phone' => $request->telephone,
            'additional_info' => $request->informations_supplementaires,
        ]);

        if (!$request->filled('mot_de_passe')) {
            $verificationLinks->send($user, trim($request->prenom . ' ' . $request->nom), $request);
        }

        return response()->json([
            'message' => 'Commercial créé avec succès.',
            'utilisateur' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'employee' => $employee,
        ], 201);
    }

    public function statistiquesCommerciaux(Request $request): JsonResponse
    {
        $enterprise = $request->user()->enterprise;

        if (!$enterprise) {
            return response()->json(['message' => 'Profil entreprise non trouvé.'], 404);
        }

        $commerciaux = Employee::where('enterprise_id', $enterprise->id)
            ->with('user')
            ->get()
            ->map(fn ($employee) => [
                'id' => $employee->id,
                'prenom' => $employee->first_name,
                'nom' => $employee->last_name,
                'email' => $employee->user->email,
                'clients_assignes' => $employee->user->assignedClients()->count(),
                'reservations' => $employee->user->createdReservations()->count(),
                'notes' => $employee->user->createdNotes()->count(),
            ]);

        return response()->json([
            'commerciaux' => $commerciaux,
        ]);
    }
}
