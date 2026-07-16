<?php

namespace App\Services\Places;

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceMerge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Folds a duplicate place into a survivor (02 §3.8 / §4.3, T-035 admin flow).
 * Rehomes provenance, tombstones the loser, recomputes the survivor's counters,
 * and backfills its nulls. Every merge snapshots the pre-merge state into a
 * `place_merges` audit row so {@see unmerge()} can reverse it exactly — the
 * merge deletes duplicate sources and mutates both places, so snapshot-first
 * is the only way back. Enforces the single-hop chain rule so
 * `merged_into_place_id` never points at another merged row.
 */
class PlaceMerger
{
    /** Loser fields the merge itself mutates (and unmerge must restore). */
    private const MUTATED_FIELDS = ['google_place_id', 'status', 'merged_into_place_id'];

    /**
     * Merge $loser into $winner. Idempotent-safe: merging a place into itself or
     * into its own survivor is a no-op (and writes no audit row).
     */
    public function merge(Place $winner, Place $loser, ?User $actor = null): Place
    {
        $winner = $this->terminal($winner->fresh() ?? $winner);
        $loser = $loser->fresh() ?? $loser;

        if ($winner->id === $loser->id || $loser->status === PlaceStatus::Merged) {
            return $winner;
        }

        return DB::transaction(function () use ($winner, $loser, $actor) {
            // Lock both rows (ascending id — deterministic order prevents
            // deadlocks) and RE-CHECK state under the lock: a concurrent merge
            // of the same pair (double-submit) or of the reverse pair (A→B vs
            // B→A) passes the unlocked pre-checks and would otherwise tombstone
            // both places or write a second, corrupting audit row.
            /** @var list<Place> $locked */
            $locked = Place::query()
                ->whereIn('id', [$winner->id, $loser->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();
            [$winner, $loser] = $locked[0]->id === $winner->id ? [$locked[0], $locked[1]] : [$locked[1], $locked[0]];

            if ($loser->status === PlaceStatus::Merged) {
                return $this->terminal($winner);
            }
            if ($winner->status === PlaceStatus::Merged) {
                // The winner was merged away while we waited on the lock. Retry
                // against its survivor rather than rehoming onto a tombstone.
                return $this->merge($this->terminal($winner), $loser, $actor);
            }

            $loserSources = DB::table('place_sources')->where('place_id', $loser->id)->orderBy('id')->get();
            $winnerShareIds = DB::table('place_sources')->where('place_id', $winner->id)->pluck('share_id')->all();

            // A loser source whose share already lives on the winner would violate
            // unique(share_id) on rehome — drop it, keeping the full row in the
            // audit (defensive: the global unique makes this normally impossible).
            $dropped = $loserSources->whereIn('share_id', $winnerShareIds)->values();
            $rehomed = $loserSources->whereNotIn('share_id', $winnerShareIds)->values();

            DB::table('place_sources')->whereIn('id', $dropped->pluck('id'))->delete();

            // The winner keeps its primary; demote the loser's before rehoming so
            // the one-primary-per-place partial unique can't be violated.
            DB::table('place_sources')->where('place_id', $loser->id)->update(['is_primary' => false]);
            DB::table('place_sources')->where('place_id', $loser->id)->update(['place_id' => $winner->id]);

            $winnerPivots = $this->tagPivots($winner->id);
            $loserPivots = $this->tagPivots($loser->id);

            // Rehome discovery tags too (T-031): the winner absorbs the loser's
            // pivots (max-confidence on collision) and the tombstone sheds its
            // rows so usage counts (?popular=1) never count dead places.
            DB::statement(
                'insert into place_tag (place_id, tag_id, source, confidence)
                 select ?, tag_id, source, confidence from place_tag where place_id = ?
                 on conflict (place_id, tag_id) do update
                 set confidence = nullif(greatest(coalesce(place_tag.confidence, -1), coalesce(excluded.confidence, -1)), -1)',
                [$winner->id, $loser->id],
            );
            DB::table('place_tag')->where('place_id', $loser->id)->delete();

            // Rehome personal-collection references so a user's saved/hidden place
            // follows the merge to the survivor instead of dangling on the
            // tombstone (T-071). Move where the survivor isn't already saved/hidden
            // by that owner (the unique constraint), then drop the redundant rest.
            // NOTE: unmerge() does NOT restore these onto the resurrected loser
            // (unlike place_sources) — a saved place stays on the survivor after an
            // unmerge. Acceptable for now (admin-only, rare); a full snapshot/restore
            // into the PlaceMerge record is a follow-up.
            foreach ([['place_list_items', 'place_list_id'], ['hidden_places', 'user_id']] as [$table, $ownerCol]) {
                DB::table($table)->where('place_id', $loser->id)
                    ->whereNotIn($ownerCol, fn ($q) => $q->select($ownerCol)->from($table)->where('place_id', $winner->id))
                    ->update(['place_id' => $winner->id]);
                DB::table($table)->where('place_id', $loser->id)->delete();
            }

            // Capture the loser's data, then tombstone it — releasing its unique
            // google_place_id before the survivor can claim it in the backfill.
            $donor = $loser->getAttributes();

            $loser->google_place_id = null;
            $loser->status = PlaceStatus::Merged;
            $loser->merged_into_place_id = $winner->id;
            $loser->save();

            $backfilled = $this->backfill($winner, $donor);
            $this->ensurePrimary($winner);
            $this->recount($winner);
            $this->recount($loser); // tombstone donated everything — zero, not stale

            PlaceMerge::query()->create([
                'source_place_id' => $loser->id,
                'target_place_id' => $winner->id,
                'performed_by_user_id' => $actor?->id,
                'rehomed_place_source_ids' => $rehomed->pluck('id')->all(),
                'dropped_duplicate_place_sources' => $dropped->map(fn ($row) => (array) $row)->all(),
                'source_snapshot' => [
                    'attributes' => collect($donor)->only(self::MUTATED_FIELDS)->all(),
                    'source_primary_flags' => $loserSources->pluck('is_primary', 'id')->all(),
                    'tag_pivots' => $loserPivots,
                ],
                'target_tag_pivots' => $winnerPivots,
                'target_backfilled_fields' => $backfilled,
            ]);

            return $winner->fresh() ?? $winner;
        });
    }

    /**
     * Reverse a recorded merge: sources move back, dropped duplicates are
     * re-inserted, the tombstone's status/attributes/tags are restored, the
     * survivor's backfilled fields are nulled and its tag union rolled back,
     * and both counter sets are recomputed. Throws when the merge was already
     * undone or either place has since moved on (re-merged) — a stale snapshot
     * must never overwrite newer state.
     */
    public function unmerge(PlaceMerge $merge): Place
    {
        return DB::transaction(function () use ($merge) {
            /** @var PlaceMerge $merge */
            $merge = PlaceMerge::query()->lockForUpdate()->findOrFail($merge->id);
            /** @var Place $loser */
            $loser = Place::query()->lockForUpdate()->findOrFail($merge->source_place_id);
            /** @var Place $winner */
            $winner = Place::query()->lockForUpdate()->findOrFail($merge->target_place_id);

            if ($merge->undone_at !== null) {
                throw new RuntimeException('This merge has already been undone.');
            }
            if ($loser->status !== PlaceStatus::Merged || $loser->merged_into_place_id !== $winner->id) {
                throw new RuntimeException('The merged place has since changed — this merge can no longer be undone.');
            }
            if ($winner->status === PlaceStatus::Merged) {
                throw new RuntimeException('The surviving place was itself merged — undo that merge first.');
            }

            // Null the survivor's backfilled fields first (only where the donated
            // value still stands — an admin edit since the merge wins), releasing
            // the unique google_place_id before the tombstone reclaims it.
            // json_encode-normalized strict compare: jsonb round-trips arrays
            // faithfully, and a loose == would numeric-juggle strings ('01234'
            // == '1234'), wrongly nulling an admin's post-merge correction.
            foreach ($merge->target_backfilled_fields as $field => $value) {
                if (json_encode($winner->getAttribute($field)) === json_encode($value)) {
                    $winner->setAttribute($field, null);
                }
            }
            $winner->save();

            $snapshot = $merge->source_snapshot;
            foreach ((array) ($snapshot['attributes'] ?? []) as $field => $value) {
                $loser->setAttribute($field, $value);
            }

            // Move the rehomed sources back (guarded to rows still on the winner)
            // and restore their pre-merge primary flags.
            DB::table('place_sources')
                ->whereIn('id', $merge->rehomed_place_source_ids)
                ->where('place_id', $winner->id)
                ->update(['place_id' => $loser->id, 'is_primary' => false]);

            foreach ((array) ($snapshot['source_primary_flags'] ?? []) as $id => $isPrimary) {
                if ($isPrimary) {
                    DB::table('place_sources')
                        ->where('id', (int) $id)->where('place_id', $loser->id)
                        ->update(['is_primary' => true]);
                }
            }

            // Dropped duplicates: re-insert unless the share meanwhile has a row
            // elsewhere (unique(share_id) — normally impossible, see merge()).
            foreach ($merge->dropped_duplicate_place_sources as $row) {
                DB::table('place_sources')->insertOrIgnore($row);
            }

            $this->restoreTagPivots($winner->id, $merge->target_tag_pivots, (array) ($snapshot['tag_pivots'] ?? []));
            DB::table('place_tag')->where('place_id', $loser->id)->delete();
            foreach ($this->pivotsWithLiveTags((array) ($snapshot['tag_pivots'] ?? [])) as $pivot) {
                DB::table('place_tag')->insertOrIgnore($pivot);
            }

            $this->ensurePrimary($winner);
            $this->ensurePrimary($loser);
            $this->recount($winner);
            $loser->save();
            $this->recount($loser);

            $merge->undone_at = now();
            $merge->save();

            return $loser->fresh() ?? $loser;
        });
    }

    /**
     * Roll the winner's tag pivots back to their pre-merge snapshot without
     * losing tags gained *after* the merge from unrelated publishes: pivots in
     * the snapshot are restored exactly, pivots the loser donated are removed,
     * anything else (new since the merge) is kept.
     *
     * @param  list<array<string, mixed>>  $preMergePivots
     * @param  list<array<string, mixed>>  $loserPivots
     */
    private function restoreTagPivots(int $winnerId, array $preMergePivots, array $loserPivots): void
    {
        $snapshotByTag = collect($preMergePivots)->keyBy(fn ($pivot) => (int) $pivot['tag_id']);
        $donatedTagIds = collect($loserPivots)->map(fn ($pivot) => (int) $pivot['tag_id']);

        DB::table('place_tag')
            ->where('place_id', $winnerId)
            ->whereIn('tag_id', $donatedTagIds->diff($snapshotByTag->keys()))
            ->delete();

        // Upsert (not UPDATE): a snapshot pivot deleted since the merge must be
        // re-created, not silently skipped. Tags hard-deleted in the interim are
        // filtered out — restoring them would hit the FK.
        $rows = $this->pivotsWithLiveTags(
            $snapshotByTag->map(fn ($pivot) => ['place_id' => $winnerId] + (array) $pivot)->values()->all(),
        );
        if ($rows !== []) {
            DB::table('place_tag')->upsert($rows, ['place_id', 'tag_id'], ['source', 'confidence']);
        }
    }

    /**
     * Keep only snapshot pivots whose tag still exists (normalized to arrays
     * with any stale place_id overwritten by the snapshot's own value).
     *
     * @param  list<array<string, mixed>>  $pivots
     * @return list<array<string, mixed>>
     */
    private function pivotsWithLiveTags(array $pivots): array
    {
        $pivots = array_map(fn ($pivot) => (array) $pivot, $pivots);
        $live = DB::table('tags')
            ->whereIn('id', array_map(fn (array $pivot) => (int) $pivot['tag_id'], $pivots))
            ->pluck('id')
            ->all();

        return array_values(array_filter($pivots, fn (array $pivot) => in_array((int) $pivot['tag_id'], $live, true)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tagPivots(int $placeId): array
    {
        return DB::table('place_tag')
            ->where('place_id', $placeId)
            ->orderBy('tag_id')
            ->get(['place_id', 'tag_id', 'source', 'confidence'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** Guarantee exactly-one-primary holds after rehoming (promote if none). */
    private function ensurePrimary(Place $place): void
    {
        $hasPrimary = DB::table('place_sources')
            ->where('place_id', $place->id)
            ->where('is_primary', true)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        $first = DB::table('place_sources')->where('place_id', $place->id)->orderBy('id')->first();
        if ($first !== null) {
            DB::table('place_sources')->where('id', $first->id)->update(['is_primary' => true]);
        }
    }

    /**
     * Copy the loser's non-null scalar fields into the winner's empty ones. Takes
     * the loser's raw attributes captured *before* it was tombstoned (its unique
     * google_place_id is released on tombstone so the winner can claim it here).
     * Returns field => donated value for the audit row.
     *
     * @param  array<string, mixed>  $donor
     * @return array<string, mixed>
     */
    private function backfill(Place $winner, array $donor): array
    {
        $fields = [
            'google_place_id', 'address_line1', 'address_line2', 'city', 'region',
            'postal_code', 'cuisine_primary', 'price_range', 'phone', 'website',
            'opening_hours_json',
        ];

        $backfilled = [];
        foreach ($fields as $field) {
            if ($winner->{$field} === null && ($donor[$field] ?? null) !== null) {
                $winner->{$field} = $donor[$field];
                $backfilled[$field] = $winner->getAttribute($field);
            }
        }

        if ($winner->isDirty()) {
            $winner->save();
        }

        return $backfilled;
    }

    /**
     * Recompute counters from place_sources aggregates — merges, unmerges and
     * duplicate drops make incremental math wrong (always recount, never adjust).
     */
    private function recount(Place $place): void
    {
        $place->shares_count = DB::table('place_sources')->where('place_id', $place->id)->count();

        $avg = DB::table('place_sources')
            ->join('analysis_runs', 'analysis_runs.id', '=', 'place_sources.analysis_run_id')
            ->where('place_sources.place_id', $place->id)
            ->whereNotNull('analysis_runs.overall_confidence')
            ->avg('analysis_runs.overall_confidence');
        $place->avg_extraction_confidence = $avg !== null ? (float) $avg : null;

        $place->save();
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
