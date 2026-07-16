<?php

use App\Enums\PlaceStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the places.status CHECK to admit the new `removed` tombstone (T-073):
 * a place auto-orphaned when its last contributor fully removed it. Enum columns
 * here are varchar + CHECK (see {@see Constraints}), so evolving the set is just
 * a constraint rebuild — no type migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE places DROP CONSTRAINT places_status_check');
        // Rebuilds from PlaceStatus::cases(), which now includes `removed`.
        Constraints::enumCheck('places', 'status', PlaceStatus::class);
    }

    public function down(): void
    {
        // Any tombstoned rows must leave the `removed` state before the tighter
        // CHECK can be restored, or the ALTER would fail validation. Fold them
        // back to `pending` (harmless: they carry no published source, so they
        // stay off every public surface via other gates until re-shared).
        DB::table('places')->where('status', PlaceStatus::Removed->value)
            ->update(['status' => PlaceStatus::Pending->value]);

        DB::statement('ALTER TABLE places DROP CONSTRAINT places_status_check');
        DB::statement(
            'ALTER TABLE places ADD CONSTRAINT places_status_check '
            ."CHECK (status IN ('pending','active','merged','hidden'))"
        );
    }
};
