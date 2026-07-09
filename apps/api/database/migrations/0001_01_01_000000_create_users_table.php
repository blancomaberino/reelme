<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users` per 02-data-model.md §3.1. Roles are boolean flags (a user can be a
 * diner + influencer + restaurant owner simultaneously). `username`/`email` are
 * citext (case-insensitive uniqueness). Framework tables kept at defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('username'); // → citext below
            $table->string('email');    // → citext below
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password', 255)->nullable(); // null for social-only signups
            $table->string('avatar_path', 2048)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_influencer')->default(false);
            $table->boolean('is_restaurant_owner')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->string('preferred_analysis_model', 120)->nullable();
            $table->string('stripe_connect_account_id', 255)->nullable();
            $table->timestampTz('stripe_connect_onboarded_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // citext for case-insensitive handle/email uniqueness.
        DB::statement('ALTER TABLE users ALTER COLUMN username TYPE citext');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
            $table->unique('email');
            $table->index('is_admin');
        });

        // Partial unique: at most one row per Stripe Connect account, ignoring nulls.
        DB::statement('CREATE UNIQUE INDEX users_stripe_connect_account_id_unique ON users (stripe_connect_account_id) WHERE stripe_connect_account_id IS NOT NULL');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
