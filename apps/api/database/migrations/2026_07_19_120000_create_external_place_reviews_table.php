<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Out-of-band cache for external review providers (T-082). Google keeps its own
 * dedicated columns on `places` (back-compat with T-059/T-080); every *other*
 * source (Trustpilot today) caches its ToS-compliant summary here — one row per
 * (place, source) — refreshed by a sweep, never fetched inline on the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_place_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            // 'trustpilot', … — the ReviewSource driver id this row caches.
            $table->string('source', 32);
            // 0–5 normalized average; null when the source carried no score.
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('review_count')->default(0);
            // Deep link to the full reviews on the source (read-more).
            $table->string('url')->nullable();
            // Normalized ReviewSnippet[] excerpts (ToS-bounded content).
            $table->jsonb('snippets_json')->nullable();
            // When the summary was last fetched — drives the per-source ToS window.
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['place_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_place_reviews');
    }
};
