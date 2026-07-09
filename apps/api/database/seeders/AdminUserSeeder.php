<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/dev only — creates a known admin for the Filament panel.
 * NEVER run in production; promote real admins with `php artisan app:make-admin`.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@reelmap.test'],
            [
                'name' => 'Reelmap Admin',
                'username' => 'admin',
                'password' => Hash::make('password'),
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
