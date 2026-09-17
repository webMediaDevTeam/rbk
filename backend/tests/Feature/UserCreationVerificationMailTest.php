<?php

namespace Tests\Feature;

use App\Mail\UserAccountVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCreationVerificationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_user_without_password_queues_account_verification_link(): void
    {
        Mail::fake();

        $actor = User::factory()->create([
            'role' => 'SUPER_ADMIN',
        ]);

        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/users', [
            'email' => 'new.admin@example.com',
            'role' => 'ADMIN',
            'first_name' => 'New',
            'last_name' => 'Admin',
        ]);

        $response->assertCreated();

        $createdUser = User::where('email', 'new.admin@example.com')->firstOrFail();

        $this->assertNotNull($createdUser->verification_token);
        $this->assertNull($createdUser->email_verified_at);

        Mail::assertSent(UserAccountVerificationMail::class, function (UserAccountVerificationMail $mail) use ($createdUser) {
            return $mail->hasTo($createdUser->email)
                && $mail->token === $createdUser->verification_token
                && str_contains($mail->verificationUrl, '/verify-account?token=' . $createdUser->verification_token)
                && $mail->userName === 'New Admin';
        });
    }

}
