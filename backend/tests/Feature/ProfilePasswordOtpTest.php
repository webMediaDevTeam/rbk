<?php

namespace Tests\Feature;

use App\Mail\OtpVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePasswordOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_password_update_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'profile@example.com',
            'first_name' => 'Profile',
            'last_name' => 'User',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/profile/password/otp');

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Un code de vérification a été envoyé à votre adresse email.',
            ]);

        Mail::assertQueued(OtpVerificationMail::class, function (OtpVerificationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->userName === 'Profile User'
                && strlen($mail->code) === 6;
        });
    }

    public function test_user_can_verify_otp_then_update_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password_hash' => Hash::make('old-password'),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/profile/password/otp')->assertOk();

        $queuedMail = Mail::queued(OtpVerificationMail::class)->first();

        $verifyResponse = $this->postJson('/api/v1/auth/profile/password/otp/verify', [
            'code' => $queuedMail->code,
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonStructure(['password_update_token']);

        $passwordUpdateToken = $verifyResponse->json('password_update_token');

        $this->putJson('/api/v1/auth/profile/password', [
            'password_update_token' => $passwordUpdateToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password_hash));
    }

    public function test_expired_password_otp_can_be_resent(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Cache::put("password_update_otp:{$user->id}", [
            'code_hash' => Hash::make('123456'),
        ], now()->subMinute());

        $response = $this->postJson('/api/v1/auth/profile/password/otp/verify', [
            'code' => '123456',
        ]);

        $response
            ->assertStatus(400)
            ->assertJson([
                'code' => 'PASSWORD_OTP_EXPIRED',
            ]);

        $this->postJson('/api/v1/auth/profile/password/otp')->assertOk();

        Mail::assertQueued(OtpVerificationMail::class);
    }
}
