<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Idempotent local accounts only — the
     * Filament admin and the user1..3@example.com test users — so it can run on
     * every dev boot and recreate them after a DB reset.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            DevUsersSeeder::class,
        ]);
    }
}
