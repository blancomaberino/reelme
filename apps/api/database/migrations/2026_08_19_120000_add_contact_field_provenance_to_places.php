<?php

use App\Enums\ContactFieldSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record where a place's `website` / `phone` came from (T-117 / SEC-1). An
 * automatic place claim (website token, phone OTP) is only a proof of ownership
 * when the listed contact field came from a provider the claimant cannot
 * control — {@see ContactFieldSource}. Until now the row kept no
 * provenance, so an extraction-sourced website (which the sharer can rewrite via
 * PATCH /shares) was indistinguishable from a Google-sourced one, and either
 * could back a `website` claim. These two columns make provenance a fact on the
 * row instead of a guess at claim time.
 *
 * Backfill is deliberately UNTRUSTED: provenance for rows written before this
 * change is unknown, and "unknown" must read as untrusted — otherwise the
 * migration would hand the very attack it closes to every place already in the
 * database. Any existing non-null website/phone is stamped `extraction`; a real
 * owner of such a place can still claim it by document, or an admin re-enrich
 * restamps it `google`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->string('website_source', 24)->nullable()->after('website');
            $table->string('phone_source', 24)->nullable()->after('phone');
        });

        // Untrusted backfill: only rows that actually carry a value need a source,
        // and every one of them is treated as claimant-nominated until proven
        // otherwise. A null field keeps a null source.
        DB::table('places')->whereNotNull('website')->update(['website_source' => 'extraction']);
        DB::table('places')->whereNotNull('phone')->update(['phone_source' => 'extraction']);
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->dropColumn(['website_source', 'phone_source']);
        });
    }
};
