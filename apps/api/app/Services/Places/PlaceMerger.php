<?php

namespace App\Services\Places;

use App\Enums\PlaceStatus;
use App\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * Folds a duplicate place into a survivor (02 §3.8, dedup admin flow — the
 * Filament UI lands in T-035, this ships the service). Rehomes provenance,
 * tombstones the loser, recomputes the survivor's counters, and backfills its
 * nulls. Enforces the single-hop chain rule so `merged_into_place_id` never
 * points at another merged row.
 */
class PlaceMerger
{
    /**
     * Merge $loser into $winner. Idempotent-safe: merging a place into itself or
     * into its own survivor is a no-op.
     */
    public function merge(Place $winner, Place $loser): Place
    {
        $winner = $this->terminal($winner->fresh() ?? $winner);
        $loser = $loser->fresh() ?? $loser;

        if ($winner->id === $loser->id || $loser->status === PlaceStatus::Merged) {
            return $winner;
        }

        DB::transaction(function () use ($winner, $loser) {
            // Drop any loser source whose share already lives on the winner
            // (defensive — share_id is globally unique, so this normally no-ops).
            DB::statement(
                'DELETE FROM place_sources loser
                 WHERE loser.place_id = ?
                   AND EXISTS (SELECT 1 FROM place_sources win WHERE win.place_id = ? AND win.share_id = loser.share_id)',
                [$loser->id, $winner->id]
            );

            // The winner keeps its primary; demote the loser's before rehoming so
            // the one-primary-per-place partial unique can't be violated.
            DB::table('place_sources')->where('place_id', $loser->id)->update(['is_primary' => false]);
            DB::table('place_sources')->where('place_id', $loser->id)->update(['place_id' => $winner->id]);

            // Capture the loser's data, then tombstone it — releasing its unique
            // google_place_id before the survivor can claim it in the backfill.
            $donor = $loser->getAttributes();

            $loser->google_place_id = null;
            $loser->status = PlaceStatus::Merged;
            $loser->merged_into_place_id = $winner->id;
            $loser->save();

            $this->backfill($winner, $donor);
            $this->ensurePrimary($winner);
            $this->recountShares($winner);
        });

        return $winner->fresh() ?? $winner;
    }

    /** Guarantee exactly-one-primary holds after rehoming (promote if none). */
    private function ensurePrimary(Place $winner): void
    {
        $hasPrimary = DB::table('place_sources')
            ->where('place_id', $winner->id)
            ->where('is_primary', true)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        $first = DB::table('place_sources')->where('place_id', $winner->id)->orderBy('id')->first();
        if ($first !== null) {
            DB::table('place_sources')->where('id', $first->id)->update(['is_primary' => true]);
        }
    }

    /**
     * Copy the loser's non-null scalar fields into the winner's empty ones. Takes
     * the loser's raw attributes captured *before* it was tombstoned (its unique
     * google_place_id is released on tombstone so the winner can claim it here).
     *
     * @param  array<string, mixed>  $donor
     */
    private function backfill(Place $winner, array $donor): void
    {
        $fields = [
            'google_place_id', 'address_line1', 'address_line2', 'city', 'region',
            'postal_code', 'cuisine_primary', 'price_range', 'phone', 'website',
            'opening_hours_json',
        ];

        foreach ($fields as $field) {
            if ($winner->{$field} === null && ($donor[$field] ?? null) !== null) {
                $winner->{$field} = $donor[$field];
            }
        }

        if ($winner->isDirty()) {
            $winner->save();
        }
    }

    private function recountShares(Place $winner): void
    {
        $count = DB::table('place_sources')->where('place_id', $winner->id)->count();
        $winner->shares_count = $count;
        $winner->save();
    }

    /**
     * Follow the merge chain to the live survivor. Loops (not a single hop) so a
     * later admin double-merge can't leave us rehoming onto a tombstone; the
     * visited guard makes a corrupt cycle terminate instead of spinning.
     */
    private function terminal(Place $place): Place
    {
        $seen = [];
        while ($place->merged_into_place_id !== null && ! in_array($place->id, $seen, true)) {
            $seen[] = $place->id;
            $next = $place->mergedInto()->first();
            if ($next === null) {
                break;
            }
            $place = $next;
        }

        return $place;
    }
}
