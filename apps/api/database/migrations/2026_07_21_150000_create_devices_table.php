<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expo push-token registry (02-data-model §3.19, T-027). One row per install:
 * a token is unique across the table, and re-registration reassigns it to the
 * currently authenticated user (tokens are per-install, not per-user — a shared
 * device must not deliver user A's pushes to user B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('expo_push_token')->unique();
            $table->string('platform', 8); // ios | android
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 24)->nullable();
            $table->timestampTz('last_seen_at')->useCurrent();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
