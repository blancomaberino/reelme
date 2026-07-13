<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "hide from my feed" (non-destructive): a user can dismiss a
 * published share so it drops out of *their* feed while staying live for
 * everyone else and on the map. Reversible — deleting the row un-hides it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('share_id')->constrained('shares')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['user_id', 'share_id']);
            // The feed filter probes by (user_id, share_id); index the share side
            // too so a share's dismissals are cheap to sweep on cascade.
            $table->index('share_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_dismissals');
    }
};
