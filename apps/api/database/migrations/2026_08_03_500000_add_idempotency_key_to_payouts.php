<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `Idempotency-Key` on a payout request (T-046, 03 §1).
 *
 * A phone on a bad connection retries a request whose answer it never saw.
 * Without this, that retry is either a second payout or — once the first hold
 * has landed — a baffling "insufficient balance" for money the user can plainly
 * see on screen. The key maps the retry back to the payout the first call
 * produced.
 *
 * Unique per USER, not globally: the key is client-generated, so two people
 * whose devices happen to mint the same string must not collide. Partial, so
 * the many payouts with no key (the scheduled monthly run) do not fight over
 * NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX payouts_idempotency_key_per_user
             ON payouts (user_id, idempotency_key) WHERE idempotency_key IS NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payouts_idempotency_key_per_user');

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
