<?php

namespace App\Services\Places;

use App\Enums\ContactFieldSource;
use App\Models\Place;
use App\Models\PlaceEdit;
use App\Support\OpeningHours;
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
 *  4. **Shaped.** A field whose stored value has a contract is coerced to it
 *     ({@see normalize()}), so no origin can write a shape its readers forbid.
 */
class PlaceEditor
{
    /**
     * Apply a curated-field patch to a place and record it. Returns the audit
     * row, or null when the (filtered) patch changed nothing.
     *
     * @param  array<string, mixed>  $patch  field => new value; non-curated keys ignored
     * @param  string  $origin  one of the PlaceEdit::ORIGIN_* constants
     * @param  array<string, ContactFieldSource>  $contactSources  winning provider per contact field (website/phone); only ContactFieldSource::Google is claim-trusted (T-117)
     */
    public function apply(
        Place $place,
        array $patch,
        string $origin,
        ?int $userId = null,
        ?string $note = null,
        array $contactSources = [],
    ): ?PlaceEdit {
        // Only curated fields are writable. Filter to the writable set before we
        // take the lock so an all-noise patch never opens a transaction.
        $patch = $this->normalize(array_intersect_key($patch, array_flip(Place::CURATED_FIELDS)));
        if ($patch === []) {
            return null;
        }

        return DB::transaction(function () use ($place, $patch, $origin, $userId, $note, $contactSources): ?PlaceEdit {
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
            // The effective diff, against the just-locked authoritative row.
            $changes = $this->diff($locked, $patch);
            if ($changes === []) {
                return null;
            }

            $locked->fill($patch);

            // Record provenance for the contact fields a claim verifies against
            // (T-117 / SEC-1). A field is provider-verified ONLY when a provider's
            // API supplied it. Enrichment merges several sources — Google's Places
            // API (trusted) AND a scrape of `places.website` (which for an
            // unclaimed pin is a URL the sharer nominated, so NOT trusted). The
            // enricher passes the winning source per contact field; anything not
            // explicitly Google — a website scrape, a review, or a manual/system
            // write — is untrusted and cannot back an automatic claim. Stamped
            // only when the field changed, so an unrelated patch never rewrites a
            // field's history.
            $sourceFor = function (string $field) use ($origin, $contactSources): ContactFieldSource {
                if ($origin === PlaceEdit::ORIGIN_ENRICHMENT) {
                    return ($contactSources[$field] ?? null) === ContactFieldSource::Google
                        ? ContactFieldSource::Google
                        : ContactFieldSource::Extraction;
                }

                return ContactFieldSource::Manual;
            };
            if (array_key_exists('website', $changes)) {
                $locked->website_source = $sourceFor('website');
            }
            if (array_key_exists('phone', $changes)) {
                $locked->phone_source = $sourceFor('phone');
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

    /**
     * The effective, curated-field diff a patch would produce against a place —
     * `{field: {from, to}}`, empty when it would change nothing.
     *
     * Public because a *proposed* edit (T-083) has to be diffed at submit time,
     * hours before anyone applies it: the moderation queue shows `from → to`, and
     * a form submitted with nothing touched must be refused rather than queued as
     * an empty proposal. Same comparison as {@see apply()} by construction — apply
     * calls this — so a suggestion can never claim a change the write path would
     * then treat as a no-op.
     *
     * Runs against a copy: the caller's instance is not modified.
     *
     * @param  array<string, mixed>  $patch  field => new value; non-curated keys ignored
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(Place $place, array $patch): array
    {
        // Normalized here TOO, not only in apply(). `submit()` diffs a raw
        // request patch to build the suggestion's stored `{from, to}` audit, and
        // an un-normalized `to` would make the audit row say `{"monday":"9-5"}`
        // while the column apply() writes holds `["monday: 9-5"]` — the record
        // of a change disagreeing with the change. `normalize()` is idempotent,
        // so apply()'s own call re-running it here costs nothing.
        $patch = $this->normalize(array_intersect_key($patch, array_flip(Place::CURATED_FIELDS)));
        if ($patch === []) {
            return [];
        }

        // Capture before-values (cast) so the diff is over real, effective changes.
        $probe = clone $place;
        $before = [];
        foreach (array_keys($patch) as $field) {
            $before[$field] = $probe->getAttribute($field);
        }

        // Fill so each proposed value is compared the way it would be STORED —
        // "3" and 3 are the same price range, and a raw string comparison would
        // record a change nobody made.
        $probe->fill($patch);

        $changes = [];
        foreach (array_keys($patch) as $field) {
            $to = $probe->getAttribute($field);
            if ($this->differs($before[$field], $to)) {
                $changes[$field] = ['from' => $before[$field], 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * Coerce the values whose STORED SHAPE has a contract, so a patch cannot
     * write one the column's readers forbid (T-128).
     *
     * Here, and not in the callers, because this class is the line every write
     * crosses — the moderator approving a stranger's proposal
     * (`PlaceSuggestionService::approve()` → `PlaceEditSuggestion::patch()`), the
     * operator's own edit applying on submit (`submit()` → `applyAsOwner()`,
     * which never touches `patch()`), a Filament edit, and an enrichment run.
     * The same reason the field allow-list and the manual-override lock live
     * here: two guards for one invariant is the split that drifts, and the half
     * that drifts is the one nobody exercises.
     *
     * Field-name filtering alone is only half a chokepoint:
     * `SuggestPlaceEditRequest` accepted a bare `array` for `opening_hours_json`
     * until T-128, so a row queued before that fix still carries
     * `{"monday": "9-5"}` and would apply it verbatim.
     *
     * `salvage()`, not `fromProvider()` — see {@see OpeningHours} for the
     * strict-vs-lenient rule. `null` — a patch that CLEARS the hours — stays null.
     *
     * Idempotent: normalizing an already-normalized patch returns it unchanged.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function normalize(array $patch): array
    {
        if (array_key_exists('opening_hours_json', $patch)) {
            $patch['opening_hours_json'] = OpeningHours::salvage($patch['opening_hours_json']);
        }

        return $patch;
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
