<?php

use App\Enums\PlaceStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T-035: admins can hide a place (spam / not a restaurant). Enum columns are
 * varchar + CHECK, so adding the `hidden` state is a constraint rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE places DROP CONSTRAINT places_status_check');
        Constraints::enumCheck('places', 'status', PlaceStatus::class);
    }

    public function down(): void
    {
        // Postgres validates existing rows when adding a CHECK — park any
        // hidden rows back in the review queue or the rebuild aborts halfway
        // (constraint dropped, replacement never added).
        DB::statement("UPDATE places SET status = 'pending' WHERE status = 'hidden'");
        DB::statement('ALTER TABLE places DROP CONSTRAINT places_status_check');
        DB::statement("ALTER TABLE places ADD CONSTRAINT places_status_check CHECK (status IN ('pending','active','merged'))");
    }
};
