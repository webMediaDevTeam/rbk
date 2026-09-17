<?php

namespace Tests\Feature;

use App\Mail\OtpVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginAndForgotPasswordOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_email_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'otp-login@example.com',
            'first_name' => 'Otp',
            'last_name' => 'Login',
        ]);

        $this->postJson('/api/v1/auth/login/otp', [
            'email' => $user->email,
        ])->assertOk();

        $mail = Mail::queued(OtpVerificationMail::class)->first();

        $this->postJson('/api/v1/auth/login/otp/verify', [
            'email' => $user->email,
            'code' => $mail->code,
        ])
            ->assertOk()
            ->assertJsonStructure(['utilisateur', 'jeton']);
    }

    public function test_forgot_password_otp_resets_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'forgot@example.com',
            'password_hash' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        $mail = Mail::queued(OtpVerificationMail::class)->first();

        $verifyResponse = $this->postJson('/api/v1/auth/forgot-password/verify', [
            'email' => $user->email,
            'code' => $mail->code,
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonStructure(['password_reset_token']);

        $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => $user->email,
            'password_reset_token' => $verifyResponse->json('password_reset_token'),
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password_hash));
    }
}
