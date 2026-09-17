<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'superadmin@rbk.local')],
            [
                'password_hash' => Hash::make(env('SUPER_ADMIN_PASSWORD', 'password')),
                'role' => 'SUPER_ADMIN',
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
                'verification_token' => null,
                'verification_sent_at' => null,
            ],
        );

        User::updateOrCreate(
            ['email' => 'admin@admin.tn'],
            [
                'password_hash' => Hash::make('123456789'),
                'role' => 'SUPER_ADMIN',
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
                'verification_token' => null,
                'verification_sent_at' => null,
            ],
        );

        $this->call(QuebecConstructionSeeder::class);
    }
}
