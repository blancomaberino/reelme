<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the redundant standalone `sha256` index. The composite
 * unique(['sha256', 'source_post_id']) already indexes `sha256` as its
 * leftmost column, so Postgres serves sha256-only lookups from the composite —
 * the separate single-column index is dead weight on every write. (T-058)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['sha256']);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->index('sha256');
        });
    }
};
