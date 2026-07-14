<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grandfather existing accounts into the new login email-verification gate
 * (T-066). Accounts created before this feature never had a way to confirm, so
 * marking them verified at deploy time keeps them able to log in — the gate then
 * only applies to genuinely new signups. (No-op on a fresh DB: there are no rows
 * yet when migrations run.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // One-way data backfill — nothing to reverse.
    }
};
