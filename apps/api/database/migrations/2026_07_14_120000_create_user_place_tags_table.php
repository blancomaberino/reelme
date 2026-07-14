<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private per-user place tags/notes (T-064). Strictly personal annotations a
 * user pins to a place (e.g. "visitar a las 5") — owner-only, NEVER exposed to
 * other users or aggregated into the public/AI discovery tags (App\Models\Tag).
 * One label per (user, place); deleting the user or place cascades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_place_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->timestamps();

            // Reverse lookups / cascade on place delete.
            $table->index('place_id');
        });

        // One label per place per owner, matched CASE-INSENSITIVELY — the same
        // rule the controller dedups on (lower(label)). A functional unique index
        // (not a plain unique(user,place,label)) makes the DB a true backstop:
        // a mixed-case concurrent insert ("visitar" + "Visitar") also trips it,
        // so it can never persist two rows the app considers the same tag. The
        // leading (user_id, place_id) columns also serve the owner's-tags lookup.
        DB::statement(
            'CREATE UNIQUE INDEX user_place_tags_user_place_lower_label_unique '
            .'ON user_place_tags (user_id, place_id, lower(label))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_place_tags');
    }
};
