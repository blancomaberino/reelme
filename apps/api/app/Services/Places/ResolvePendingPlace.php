<?php

namespace App\Services\Places;

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolve or dismiss a single PENDING venue on an already-(partially-)published
 * multi-place share (T-071, closes the T-013 partial-publish gap). A multi-place
 * post can publish some venues and leave others in `review_meta_json.pending[]`;
 * the share is `published`, so the whole-share review route can't touch them.
 * This attaches ONE more published place_source for a picked candidate (or drops
 * the pending entry), leaving the already-published siblings untouched.
 */
class ResolvePendingPlace
{
    public function __construct(
        private readonly PlaceResolver $resolver,
        private readonly PlacePublisher $publisher,
    ) {}

    /**
     * Attach + publish `$placeId` (a candidate the pending entry offered) as the
     * pending venue at `$index`, then drop the entry. Owner-scoped by the caller.
     * Throws ValidationException on an unknown index / off-list place. A repeat
     * call 404s once the entry is gone; a concurrent call for the same index is a
     * no-op (the row is locked and re-checked inside the transaction).
     */
    public function resolve(Share $share, int $index, int $placeId): void
    {
        $entry = $this->pendingEntry($share, $index);

        // Only a place the pending entry actually offered can be attached — a
        // share must never be pinned onto (and skew the counters of) an arbitrary
        // canonical place.
        $offered = array_map(
            fn ($c): int => (int) ($c['place_id'] ?? 0),
            is_array($entry['candidates'] ?? null) ? $entry['candidates'] : [],
        );
        if (! in_array($placeId, $offered, true)) {
            throw ValidationException::withMessages([
                'place_id' => ['The selected place is not among this venue’s candidates.'],
            ]);
        }

        $place = Place::query()
            ->whereIn('status', PlaceStatus::matchable())
            ->whereNull('merged_into_place_id')
            ->find($placeId);
        if ($place === null) {
            throw ValidationException::withMessages([
                'place_id' => ['The selected place is no longer available.'],
            ]);
        }

        $snapshot = $this->resolver->extractedPlaceAt($share, $index) ?? ['name' => $entry['name'] ?? null];

        DB::transaction(function () use ($share, $place, $snapshot, $index): void {
            // Lock + re-read: two concurrent resolves/dismisses each did a
            // read-modify-write on review_meta_json from their own stale in-memory
            // copy, so one could resurrect the other's dropped entry. Operate on
            // the locked row, and bail if this index was already handled.
            $locked = Share::query()->whereKey($share->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->hasPending($locked, $index)) {
                return;
            }

            $isPrimary = ! $place->sources()->where('is_primary', true)->exists()
                && $locked->published_place_source_id === null;

            // A pending venue was never attached (that's why it's pending), so this
            // always creates; firstOrCreate keeps it idempotent under a retry.
            // Sources are born published here (published_at = now).
            $source = PlaceSource::query()->firstOrCreate(
                ['place_id' => $place->id, 'share_id' => $locked->id],
                [
                    'source_post_id' => $locked->source_post_id,
                    'analysis_run_id' => $locked->analysis_run_id,
                    'extraction_snapshot_json' => $snapshot,
                    'is_primary' => $isPrimary,
                    'published_at' => now(),
                ],
            );
            // $isPrimary already implies the share had no primary yet.
            if ($isPrimary) {
                $locked->published_place_source_id = $source->id;
            }

            $this->publisher->recompute($place, $locked, $source);
            $this->dropPending($locked, $index);
            $locked->save();
        });
    }

    /** Drop a pending venue from the share without publishing it (T-071). */
    public function dismiss(Share $share, int $index): void
    {
        $this->pendingEntry($share, $index); // 404 if the index isn't pending

        DB::transaction(function () use ($share, $index): void {
            $locked = Share::query()->whereKey($share->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->hasPending($locked, $index)) {
                return;
            }
            $this->dropPending($locked, $index);
            $locked->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingEntry(Share $share, int $index): array
    {
        $pending = is_array($share->review_meta_json) ? ($share->review_meta_json['pending'] ?? []) : [];
        foreach (is_array($pending) ? $pending : [] as $entry) {
            if (is_array($entry) && (int) ($entry['index'] ?? -1) === $index) {
                return $entry;
            }
        }

        abort(404, 'No pending venue at that index.');
    }

    /** Whether the (freshly-read) share still lists a pending venue at `$index`. */
    private function hasPending(Share $share, int $index): bool
    {
        $pending = is_array($share->review_meta_json) ? ($share->review_meta_json['pending'] ?? []) : [];
        foreach (is_array($pending) ? $pending : [] as $entry) {
            if (is_array($entry) && (int) ($entry['index'] ?? -1) === $index) {
                return true;
            }
        }

        return false;
    }

    private function dropPending(Share $share, int $index): void
    {
        $meta = is_array($share->review_meta_json) ? $share->review_meta_json : [];
        $pending = array_values(array_filter(
            is_array($meta['pending'] ?? null) ? $meta['pending'] : [],
            fn ($e): bool => ! (is_array($e) && (int) ($e['index'] ?? -1) === $index),
        ));

        if ($pending === []) {
            $share->review_meta_json = null;
        } else {
            $meta['pending'] = $pending;
            $share->review_meta_json = $meta;
        }
    }
}
