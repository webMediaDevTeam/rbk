<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccountVerificationLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SuperAdminController extends Controller
{
    public function creerAdmin(Request $request, AccountVerificationLinks $verificationLinks): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'mot_de_passe' => 'nullable|string|min:8|confirmed',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
        ]);

        $userData = [
            'email' => $request->email,
            'role' => 'ADMIN',
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
        ];

        if ($request->filled('mot_de_passe')) {
            $userData['password_hash'] = Hash::make($request->mot_de_passe);
            $userData['email_verified_at'] = now();
        }

        $user = User::create($userData);

        if (!$request->filled('mot_de_passe')) {
            $displayName = trim(implode(' ', array_filter([
                $user->first_name,
                $user->last_name,
            ]))) ?: $user->email;

            $verificationLinks->send($user, $displayName, $request);
        }

        return response()->json([
            'message' => 'Administrateur créé avec succès.',
            'utilisateur' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
            ],
        ], 201);
    }
}
