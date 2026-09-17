<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Mail\OtpVerificationMail;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use App\Support\AccountVerificationLinks;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const VERIFICATION_LINK_TTL_HOURS = 24;
    private const PASSWORD_OTP_TTL_MINUTES = 10;
    private const PASSWORD_UPDATE_TOKEN_TTL_MINUTES = 10;
    private const LOGIN_OTP_TTL_MINUTES = 10;
    private const PASSWORD_RESET_TOKEN_TTL_MINUTES = 10;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie.',
            'utilisateur' => $this->formatUser($user),
            'profil' => $this->getProfile($user),
            'jeton' => $token,
        ], 200);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();
        $code = (string) random_int(100000, 999999);

        Cache::put($this->forgotPasswordOtpCacheKey($user->email), [
            'code_hash' => Hash::make($code),
        ], now()->addMinutes(self::PASSWORD_OTP_TTL_MINUTES));

        Mail::to($user->email)->send(new OtpVerificationMail(
            $this->verificationDisplayName($user),
            $code,
            self::PASSWORD_OTP_TTL_MINUTES,
        ));

        return response()->json([
            'message' => 'Un code de réinitialisation a été envoyé à votre adresse email.',
        ], 200);
    }

    public function verifyForgotPasswordOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|size:6',
        ]);

        $otp = Cache::get($this->forgotPasswordOtpCacheKey($request->email));

        if (!$otp) {
            return response()->json([
                'message' => 'Le code de réinitialisation a expiré. Vous pouvez demander un nouveau code.',
                'code' => 'FORGOT_PASSWORD_OTP_EXPIRED',
            ], 400);
        }

        if (!Hash::check($request->code, $otp['code_hash'])) {
            return response()->json([
                'message' => 'Code de réinitialisation invalide.',
            ], 422);
        }

        Cache::forget($this->forgotPasswordOtpCacheKey($request->email));

        $passwordResetToken = Str::random(64);
        Cache::put(
            $this->forgotPasswordResetTokenCacheKey($request->email, $passwordResetToken),
            true,
            now()->addMinutes(self::PASSWORD_RESET_TOKEN_TTL_MINUTES),
        );

        return response()->json([
            'message' => 'Code vérifié. Vous pouvez définir un nouveau mot de passe.',
            'password_reset_token' => $passwordResetToken,
        ]);
    }

    public function resetForgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password_reset_token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $cacheKey = $this->forgotPasswordResetTokenCacheKey($request->email, $request->password_reset_token);

        if (!Cache::pull($cacheKey)) {
            return response()->json([
                'message' => 'La vérification a expiré. Demandez un nouveau code.',
                'code' => 'PASSWORD_RESET_TOKEN_EXPIRED',
            ], 400);
        }

        User::where('email', $request->email)->firstOrFail()->update([
            'password_hash' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès.',
        ]);
    }

    public function sendLoginOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();
        $code = (string) random_int(100000, 999999);

        Cache::put($this->loginOtpCacheKey($user->email), [
            'code_hash' => Hash::make($code),
        ], now()->addMinutes(self::LOGIN_OTP_TTL_MINUTES));

        Mail::to($user->email)->send(new OtpVerificationMail(
            $this->verificationDisplayName($user),
            $code,
            self::LOGIN_OTP_TTL_MINUTES,
        ));

        return response()->json([
            'message' => 'Un code de connexion a été envoyé à votre adresse email.',
        ]);
    }

    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();
        $otp = Cache::get($this->loginOtpCacheKey($user->email));

        if (!$otp) {
            return response()->json([
                'message' => 'Le code de connexion a expiré. Vous pouvez demander un nouveau code.',
                'code' => 'LOGIN_OTP_EXPIRED',
            ], 400);
        }

        if (!Hash::check($request->code, $otp['code_hash'])) {
            return response()->json([
                'message' => 'Code de connexion invalide.',
            ], 422);
        }

        Cache::forget($this->loginOtpCacheKey($user->email));

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie.',
            'utilisateur' => $this->formatUser($user),
            'profil' => $this->getProfile($user),
            'jeton' => $token,
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['enterprise', 'employee']);

        return response()->json([
            'utilisateur' => $this->formatUser($user),
            'profil' => $this->getProfile($user),
        ]);
    }

    public function deconnexion(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    public function sendPasswordOtp(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = (string) random_int(100000, 999999);

        Cache::put($this->passwordOtpCacheKey($user), [
            'code_hash' => Hash::make($code),
        ], now()->addMinutes(self::PASSWORD_OTP_TTL_MINUTES));

        Mail::to($user->email)->send(new OtpVerificationMail(
            $this->verificationDisplayName($user),
            $code,
            self::PASSWORD_OTP_TTL_MINUTES,
        ));

        return response()->json([
            'message' => 'Un code de vérification a été envoyé à votre adresse email.',
        ]);
    }

    public function verifyPasswordOtp(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $otp = Cache::get($this->passwordOtpCacheKey($user));

        if (!$otp) {
            return response()->json([
                'message' => 'Le code de vérification a expiré. Vous pouvez demander un nouveau code.',
                'code' => 'PASSWORD_OTP_EXPIRED',
            ], 400);
        }

        if (!Hash::check($request->code, $otp['code_hash'])) {
            return response()->json([
                'message' => 'Code de vérification invalide.',
            ], 422);
        }

        Cache::forget($this->passwordOtpCacheKey($user));

        $passwordUpdateToken = Str::random(64);
        Cache::put(
            $this->passwordUpdateTokenCacheKey($user, $passwordUpdateToken),
            true,
            now()->addMinutes(self::PASSWORD_UPDATE_TOKEN_TTL_MINUTES),
        );

        return response()->json([
            'message' => 'Code vérifié. Vous pouvez maintenant modifier votre mot de passe.',
            'password_update_token' => $passwordUpdateToken,
        ]);
    }

    public function updateProfilePassword(Request $request): JsonResponse
    {
        $request->validate([
            'password_update_token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();
        $cacheKey = $this->passwordUpdateTokenCacheKey($user, $request->password_update_token);

        if (!Cache::pull($cacheKey)) {
            return response()->json([
                'message' => 'La vérification a expiré. Demandez un nouveau code.',
                'code' => 'PASSWORD_UPDATE_TOKEN_EXPIRED',
            ], 400);
        }

        $user->update([
            'password_hash' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'Mot de passe mis à jour.',
        ]);
    }

    public function verifyAccount(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::where('verification_token', $request->token)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Jeton de vérification invalide ou expiré.',
                'code' => 'VERIFICATION_TOKEN_INVALID_OR_EXPIRED',
            ], 400);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Ce compte a déjà été vérifié.',
            ], 400);
        }

        if ($this->verificationTokenExpired($user)) {
            return response()->json([
                'message' => 'Le lien de vérification a expiré. Vous pouvez demander un nouveau lien.',
                'code' => 'VERIFICATION_TOKEN_EXPIRED',
            ], 400);
        }

        $user->update([
            'password_hash' => Hash::make($request->password),
            'email_verified_at' => now(),
            'verification_token' => null,
            'verification_sent_at' => null,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Compte vérifié et mot de passe défini avec succès.',
            'utilisateur' => $this->formatUser($user),
            'profil' => $this->getProfile($user),
            'jeton' => $token,
        ], 200);
    }

    public function resendVerification(Request $request, AccountVerificationLinks $verificationLinks): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $user = User::where('verification_token', $request->token)->first();

        if (!$user || $user->email_verified_at) {
            return response()->json([
                'message' => 'Impossible de renvoyer un lien pour ce compte.',
            ], 400);
        }

        $verificationLinks->send($user, $this->verificationDisplayName($user), $request, regenerate: true);

        return response()->json([
            'message' => 'Un nouveau lien de vérification a été envoyé.',
        ]);
    }

    private function verificationTokenExpired(User $user): bool
    {
        return $user->verification_sent_at
            && $user->verification_sent_at->lt(now()->subHours(self::VERIFICATION_LINK_TTL_HOURS));
    }

    private function verificationDisplayName(User $user): string
    {
        return match ($user->role) {
            'ENTREPRISE' => $user->enterprise?->name ?? $user->email,
            'COMERCIAL' => trim(implode(' ', array_filter([
                $user->employee?->first_name ?? $user->first_name,
                $user->employee?->last_name ?? $user->last_name,
            ]))) ?: $user->email,
            default => trim(implode(' ', array_filter([
                $user->first_name,
                $user->last_name,
            ]))) ?: $user->email,
        };
    }

    private function passwordOtpCacheKey(User $user): string
    {
        return "password_update_otp:{$user->id}";
    }

    private function passwordUpdateTokenCacheKey(User $user, string $token): string
    {
        return "password_update_token:{$user->id}:{$token}";
    }

    private function loginOtpCacheKey(string $email): string
    {
        return 'login_otp:' . mb_strtolower($email);
    }

    private function forgotPasswordOtpCacheKey(string $email): string
    {
        return 'forgot_password_otp:' . mb_strtolower($email);
    }

    private function forgotPasswordResetTokenCacheKey(string $email, string $token): string
    {
        return 'forgot_password_reset_token:' . mb_strtolower($email) . ":{$token}";
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'avatar' => $user->avatar,
            'created_at' => $user->created_at,
        ];
    }

    private function getProfile(User $user): ?array
    {
        return match ($user->role) {
            'ENTREPRISE' => $user->enterprise ? [
                'id' => $user->enterprise->id,
                'nom' => $user->enterprise->name,
                'telephone' => $user->enterprise->phone,
                'adresse' => $user->enterprise->address,
                'numero_fiscal' => $user->enterprise->tax_number,
                'logo' => $user->enterprise->logo,
            ] : null,
            'COMERCIAL' => $user->employee ? [
                'id' => $user->employee->id,
                'prenom' => $user->employee->first_name,
                'nom' => $user->employee->last_name,
                'telephone' => $user->employee->phone,
                'entreprise_id' => $user->employee->enterprise_id,
                'image_dp' => $user->employee->image_dp,
            ] : null,
            default => null,
        };
    }
}
