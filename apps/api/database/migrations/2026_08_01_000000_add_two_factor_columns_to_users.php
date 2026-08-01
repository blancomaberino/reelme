<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP two-factor authentication (T-068).
 *
 * The secret and recovery codes are stored ENCRYPTED (cast on the model), not
 * hashed: both have to be readable again — the secret to verify each login, the
 * codes to be shown back to a user who asks for them after a password
 * confirmation. `text` rather than `string` because the ciphertext of even a
 * short secret comfortably exceeds 255 bytes.
 *
 * `two_factor_confirmed_at` is the enable switch, deliberately separate from the
 * secret: a secret alone means "setup started". Enforcing on the secret instead
 * would lock a user out the moment they opened the setup screen and walked away
 * without ever scanning the QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Unix timestamp of the last accepted TOTP window. A code stays
            // valid for its whole window, so without this the same six digits
            // could be replayed — by anyone who shoulder-surfed them — until the
            // window rolls. Verification only accepts a strictly newer window.
            $table->unsignedBigInteger('two_factor_last_used_ts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_used_ts',
            ]);
        });
    }
};
