<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_super_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'superadmin@rbk.local')->firstOrFail();

        $this->assertSame('SUPER_ADMIN', $user->role);
        $this->assertTrue(Hash::check('password', $user->password_hash));
        $this->assertNotNull($user->email_verified_at);
    }
}
