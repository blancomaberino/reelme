<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/dev only — the known test accounts (user1..3@example.com / "password").
 * Idempotent so it can run on every `dev.sh` boot; recreates the accounts after
 * a DB reset. Users are pre-verified so the T-066 login gate lets them in.
 * NEVER run in production (known passwords).
 */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        foreach ([1, 2, 3] as $n) {
            User::updateOrCreate(
                ['email' => "user{$n}@example.com"],
                [
                    'name' => "User {$n}",
                    'username' => "user{$n}",
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
