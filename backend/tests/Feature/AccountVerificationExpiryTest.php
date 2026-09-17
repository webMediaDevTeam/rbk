<?php

namespace Tests\Feature;

use App\Mail\UserAccountVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountVerificationExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_verification_token_returns_resendable_error(): void
    {
        $user = User::factory()->create([
            'password_hash' => null,
            'email_verified_at' => null,
            'verification_token' => 'expired-token',
            'verification_sent_at' => now()->subHours(25),
        ]);

        $response = $this->postJson('/api/v1/auth/verify-account', [
            'token' => $user->verification_token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response
            ->assertStatus(400)
            ->assertJson([
                'code' => 'VERIFICATION_TOKEN_EXPIRED',
            ]);
    }

    public function test_resending_verification_generates_new_token_and_queues_email(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'first_name' => 'Expired',
            'last_name' => 'User',
            'password_hash' => null,
            'email_verified_at' => null,
            'verification_token' => 'expired-token',
            'verification_sent_at' => now()->subHours(25),
        ]);

        $response = $this->postJson('/api/v1/auth/resend-verification', [
            'token' => $user->verification_token,
        ]);

        $response->assertOk();

        $user->refresh();

        $this->assertNotSame('expired-token', $user->verification_token);
        $this->assertNotNull($user->verification_sent_at);

        Mail::assertSent(UserAccountVerificationMail::class, function (UserAccountVerificationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->token === $user->verification_token
                && $mail->userName === 'Expired User';
        });
    }
}
