<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The speech transcript for a post (04 §1). Lives on source_posts — not shares —
 * so every share of the same reel reuses one transcription (TranscribeAudio,
 * T-018). Shape: {language, text, segments:[{start_ms,end_ms,text}], driver, empty}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_posts', function (Blueprint $table) {
            $table->jsonb('transcript_json')->nullable()->after('oembed_json');
        });
    }

    public function down(): void
    {
        Schema::table('source_posts', function (Blueprint $table) {
            $table->dropColumn('transcript_json');
        });
    }
};
