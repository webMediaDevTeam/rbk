<?php

namespace Tests\Feature;

use App\Mail\OtpVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Connexion par e-mail **ou** par nom d'utilisateur (« login »).
 *
 * Le champ de l'API reste nommé `email` (compatibilité) mais `login()`
 * accepte une valeur sans « @ », cherchée dans `users.username` (stocké en
 * minuscules). Les flux OTP résolvent le même identifiant vers l'e-mail du
 * compte avant d'envoyer puis de valider le code.
 */
class LoginByUsernameTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'login.test@example.com',
            'username' => 'login.test',
            'password_hash' => Hash::make('secret-password'),
        ], $overrides));
    }

    public function test_login_accepts_the_username_as_identifier(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login.test',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('utilisateur.email', 'login.test@example.com')
            ->assertJsonPath('utilisateur.username', 'login.test')
            ->assertJsonStructure(['utilisateur', 'profil', 'jeton']);
    }

    public function test_login_username_is_trimmed_and_case_insensitive(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => '  LOGIN.Test  ',
            'password' => 'secret-password',
        ])->assertOk();
    }

    public function test_login_with_email_still_works(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login.test@example.com',
            'password' => 'secret-password',
        ])->assertOk();
    }

    public function test_unknown_username_is_rejected_as_bad_credentials(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'personne.inconnue',
            'password' => 'secret-password',
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Identifiants incorrects.']);
    }

    public function test_wrong_password_is_rejected_with_username_identifier(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login.test',
            'password' => 'mauvais-mot-de-passe',
        ])->assertStatus(401);
    }

    public function test_login_otp_flow_accepts_a_username(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->postJson('/api/v1/auth/login/otp', [
            'email' => 'login.test',
        ])->assertOk();

        $mail = Mail::queued(OtpVerificationMail::class)->first();
        $this->assertNotNull($mail, 'Le code de connexion doit être envoyé.');

        $this->postJson('/api/v1/auth/login/otp/verify', [
            'email' => 'LOGIN.TEST',
            'code' => $mail->code,
        ])
            ->assertOk()
            ->assertJsonPath('utilisateur.username', 'login.test')
            ->assertJsonStructure(['utilisateur', 'jeton']);
    }

    public function test_login_otp_rejects_an_unknown_username(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->postJson('/api/v1/auth/login/otp', [
            'email' => 'personne.inconnue',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Identifiant inconnu.');

        Mail::assertNothingSent();
    }

    public function test_login_otp_still_rejects_an_unknown_email(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->postJson('/api/v1/auth/login/otp', [
            'email' => 'absent@example.com',
        ])->assertStatus(422);
    }

    public function test_forgot_password_flow_accepts_a_username(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'login.test',
        ])->assertOk();

        $mail = Mail::queued(OtpVerificationMail::class)->first();
        $this->assertNotNull($mail, 'Le code de réinitialisation doit être envoyé.');

        $verify = $this->postJson('/api/v1/auth/forgot-password/verify', [
            'email' => 'LOGIN.TEST',
            'code' => $mail->code,
        ])->assertOk();

        $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => 'login.test',
            'password_reset_token' => $verify->json('password_reset_token'),
            'password' => 'nouveau-mot-de-passe',
            'password_confirmation' => 'nouveau-mot-de-passe',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login.test',
            'password' => 'nouveau-mot-de-passe',
        ])->assertOk();
    }

    public function test_forgot_password_rejects_an_unknown_username(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'personne.inconnue',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Identifiant inconnu.');

        Mail::assertNothingSent();
    }

    public function test_login_otp_rejects_a_malformed_identifier(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->postJson('/api/v1/auth/login/otp', [
            'email' => ['login.test'],
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }
}
