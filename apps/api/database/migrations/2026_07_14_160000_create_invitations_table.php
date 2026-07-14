<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Invite a friend to Reelmap" by email (T-069). One row per sent invite; used
 * to dedupe (don't re-email the same friend within a window) and as a light
 * audit trail. Not unique — a friend can be re-invited after the cooldown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->timestampTz('created_at')->nullable();

            // Dedupe lookup: "did this inviter already email this address recently?"
            $table->index(['inviter_user_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
