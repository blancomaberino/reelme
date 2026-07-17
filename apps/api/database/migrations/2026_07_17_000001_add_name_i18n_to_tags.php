<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localized tag names (ADR-084 #2). `name` stays the canonical English label and
 * the fallback; `name_i18n` holds `{ "es": "Informal", … }`, seeded from the
 * mobile dictionary and (later) filled by AI translate-on-create. Nullable — an
 * untranslated tag simply falls back to `name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->jsonb('name_i18n')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->dropColumn('name_i18n');
        });
    }
};
