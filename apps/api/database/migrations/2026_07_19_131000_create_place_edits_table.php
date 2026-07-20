<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for changes to a place's curated business fields (T-084). One row
 * per apply — a Filament manual edit, an "enrich as business" run, or a system
 * write — recording who changed what (`changes` = {field: {from, to}}). Shared
 * mechanism the owner suggest-edit flow (T-083) can reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            // The admin who applied a manual edit; null for enrichment/system.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin', 16); // manual | enrichment | system
            // Per-field diff: {"phone": {"from": "…", "to": "…"}, …}.
            $table->jsonb('changes');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['place_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE place_edits ADD CONSTRAINT place_edits_origin_check '
            ."CHECK (origin IN ('manual', 'enrichment', 'system'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('place_edits');
    }
};
