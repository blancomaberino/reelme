<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review + publish flow (T-024, 04 §7). A user's corrected extraction is stored
 * separately from the immutable winning run (`corrected_extraction_json`), and
 * `user_confirmed` records an explicit human sign-off that promotes a first
 * source's place from `pending` to `active` at publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->jsonb('corrected_extraction_json')->nullable()->after('review_meta_json');
            $table->boolean('user_confirmed')->default(false)->after('corrected_extraction_json');
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropColumn(['corrected_extraction_json', 'user_confirmed']);
        });
    }
};
