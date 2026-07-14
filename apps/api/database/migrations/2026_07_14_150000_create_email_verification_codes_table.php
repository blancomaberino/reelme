<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email confirmation codes (T-066). A 6-digit code (stored hashed, like a
 * password-reset token) is emailed on signup; the user types it back to verify.
 * Keyed by email with one active code at a time (upsert on resend). Mirrors the
 * built-in password_reset_tokens shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('code'); // Hash::make of the 6-digit code
            // Failed-attempt counter: the code is burned after too many wrong
            // guesses so a 6-digit secret can't be brute-forced into a session.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
