<?php

use App\Enums\PlaceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-086: a place that resolved to a real Google Places establishment with at
 * least one review is third-party-verified and now activates on its first source
 * (see PlacePublisher::isGoogleVerified). This one-time backfill lifts the places
 * that were published BEFORE that rule — currently stuck `pending` despite carrying
 * a google_place_id and reviews — up to `active`, so they match the new invariant.
 *
 * Only `pending` rows move (never overriding Merged/Hidden/Removed). Irreversible
 * by design: `down()` cannot know which of these were pending for other reasons, so
 * it is a deliberate no-op rather than a guess that would wrongly demote places.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('places')
            ->where('status', PlaceStatus::Pending->value)
            ->whereNotNull('google_place_id')
            ->where('google_rating_count', '>=', 1)
            ->update([
                'status' => PlaceStatus::Active->value,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible — see the class docblock.
    }
};
