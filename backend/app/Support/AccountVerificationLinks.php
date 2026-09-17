<?php

namespace App\Support;

use App\Mail\UserAccountVerificationMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountVerificationLinks
{
    public function send(User $user, ?string $userName = null, ?Request $request = null, bool $regenerate = false): void
    {
        $token = $regenerate || !$user->verification_token
            ? Str::random(64)
            : $user->verification_token;

        $user->forceFill([
            'verification_token' => $token,
            'verification_sent_at' => now(),
        ])->save();

        Mail::to($user->email)->send(new UserAccountVerificationMail(
            $userName ?: $this->displayName($user),
            $this->verificationUrl($token, $request),
            $token,
        ));
    }

    private function verificationUrl(string $token, ?Request $request): string
    {
        $frontendUrl = env('FRONTEND_URL')
            ?: config('app.frontend_url')
            ?: 'http://localhost:5173';

        return rtrim($frontendUrl, '/') . '/verify-account?token=' . $token;
    }

    private function displayName(User $user): string
    {
        $name = trim(implode(' ', array_filter([
            $user->first_name,
            $user->last_name,
        ])));

        return $name !== '' ? $name : $user->email;
    }
}
