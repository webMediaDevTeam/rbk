<?php

namespace Tests\Feature;

use App\Mail\UserAccountVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminCreatesAdminVerificationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_creation_without_password_queues_verification_email(): void
    {
        Mail::fake();

        $superAdmin = User::factory()->create([
            'role' => 'SUPER_ADMIN',
        ]);

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/admins', [
            'email' => 'new.admin@example.com',
            'first_name' => 'New',
            'last_name' => 'Admin',
            'phone' => '418-555-0199',
        ]);

        $response->assertCreated();

        $admin = User::where('email', 'new.admin@example.com')->firstOrFail();

        $this->assertSame('ADMIN', $admin->role);
        $this->assertSame('New', $admin->first_name);
        $this->assertSame('Admin', $admin->last_name);
        $this->assertSame('418-555-0199', $admin->phone);
        $this->assertNotNull($admin->verification_token);
        $this->assertNotNull($admin->verification_sent_at);
        $this->assertNull($admin->email_verified_at);

        Mail::assertSent(UserAccountVerificationMail::class, function (UserAccountVerificationMail $mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && $mail->token === $admin->verification_token
                && $mail->userName === 'New Admin'
                && str_contains($mail->verificationUrl, '/verify-account?token=' . $admin->verification_token);
        });
    }
}
