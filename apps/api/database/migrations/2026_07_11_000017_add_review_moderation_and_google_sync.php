<?php

use App\Models\ReviewReport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-059: review moderation (hide flag + user reports feeding the Filament
 * queue) and the Google-ToS sync timestamp — cached Places review content
 * must be refreshed or dropped after ~30 days; `google_reviews_synced_at`
 * records capture time so the scheduled refresh knows what is stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false);
        });

        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['review_id', 'user_id']);
        });
        // Generated from the model constant so validation and the DB CHECK
        // can never drift apart.
        DB::statement(
            "ALTER TABLE review_reports ADD CONSTRAINT review_reports_reason_check CHECK (reason IN ('".implode("', '", ReviewReport::REASONS)."'))"
        );

        Schema::table('places', function (Blueprint $table) {
            $table->timestampTz('google_reviews_synced_at')->nullable();
        });
        // Existing cached reviews: best-guess capture time = row update time,
        // so the 30-day clock starts counting from a real timestamp.
        DB::statement('UPDATE places SET google_reviews_synced_at = updated_at WHERE google_reviews_json IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('google_reviews_synced_at');
        });
        Schema::dropIfExists('review_reports');
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
