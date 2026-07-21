<?php

namespace App\Services\Places;

use App\Models\Place;
use App\Models\PlaceEdit;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for a place's curated business fields (T-084). Every
 * origin — a Filament manual edit, an "enrich as business" run, a system write —
 * flows through {@see apply()} so the three invariants hold in exactly one place:
 *
 *  1. **Manual overrides win.** A non-manual patch never touches a locked field
 *     ({@see Place::withoutLockedFields()}); a manual edit locks every field it
 *     changes, so a later enrichment/re-share can't clobber it.
 *  2. **Audited.** Any real change writes one {@see PlaceEdit} row with the
 *     per-field from→to diff; a no-op patch writes nothing.
 *  3. **Scoped.** Only {@see Place::CURATED_FIELDS} are writable here.
 */
class PlaceEditor
{
    /**
     * Apply a curated-field patch to a place and record it. Returns the audit
     * row, or null when the (filtered) patch changed nothing.
     *
     * @param  array<string, mixed>  $patch  field => new value; non-curated keys ignored
     * @param  string  $origin  one of the PlaceEdit::ORIGIN_* constants
     */
    public function apply(
        Place $place,
        array $patch,
        string $origin,
        ?int $userId = null,
        ?string $note = null,
    ): ?PlaceEdit {
        // Only curated fields are writable. Filter to the writable set before we
        // take the lock so an all-noise patch never opens a transaction.
        $patch = array_intersect_key($patch, array_flip(Place::CURATED_FIELDS));
        if ($patch === []) {
            return null;
        }

        return DB::transaction(function () use ($place, $patch, $origin, $userId, $note): ?PlaceEdit {
            // Lock + refetch the authoritative row so the locked_fields check, the
            // diff, and the save all run against state that cannot change under us.
            // This closes the enrichment-clobbers-manual-edit race (T-085): a manual
            // PATCH that commits during enrichment's multi-second network I/O window
            // is now visible here and wins, instead of being diffed against a stale
            // `locked_fields = []` snapshot loaded before that I/O.
            $locked = Place::query()->whereKey($place->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            // A non-manual origin can never touch a human-locked field (manual
            // override wins) — evaluated against the just-locked, authoritative row.
            if ($origin !== PlaceEdit::ORIGIN_MANUAL) {
                $patch = $locked->withoutLockedFields($patch);
            }
            if ($patch === []) {
                return null;
            }

            // Capture before-values (cast) so the diff is over real, effective changes.
            $before = [];
            foreach (array_keys($patch) as $field) {
                $before[$field] = $locked->getAttribute($field);
            }

            $locked->fill($patch);

            $changes = [];
            foreach ($patch as $field => $_) {
                $to = $locked->getAttribute($field);
                if ($this->differs($before[$field], $to)) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $to];
                }
            }
            if ($changes === []) {
                return null;
            }

            // A human edit takes ownership of every field it changed.
            if ($origin === PlaceEdit::ORIGIN_MANUAL) {
                $locked->lockFields(array_keys($changes));
            }

            $locked->save();

            $edit = $locked->placeEdits()->create([
                'user_id' => $userId,
                'origin' => $origin,
                'changes' => $changes,
                'note' => $note,
            ]);

            // Reflect the committed row back onto the caller's instance so any
            // post-apply read (Filament form refresh, the enricher's `enriched_at`
            // write) sees the authoritative state, not the pre-lock snapshot.
            $place->setRawAttributes($locked->getAttributes());
            $place->syncOriginal();

            return $edit;
        });
    }

    /** Two cast attribute values differ — arrays compared by content, not identity. */
    private function differs(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a) !== json_encode($b);
        }

        return $a !== $b;
    }
}
