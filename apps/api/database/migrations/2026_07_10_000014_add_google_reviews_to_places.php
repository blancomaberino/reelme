<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Places review signal on the place detail (02 §3.8). `google_reviews_json`
 * caches the (max 5) review snippets Google returns with Place Details; the scalar
 * rating + count back the "Google rating" block. Native user reviews live in the
 * separate `reviews` table — these three columns are the third-party mirror only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->decimal('google_rating', 2, 1)->nullable()->after('website');
            $table->integer('google_rating_count')->nullable()->after('google_rating');
            $table->jsonb('google_reviews_json')->nullable()->after('google_rating_count');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn(['google_rating', 'google_rating_count', 'google_reviews_json']);
        });
    }
};
