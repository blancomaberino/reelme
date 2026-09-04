<?php

namespace App\Models\Concerns;

/**
 * Human-owned field locking (T-084), extracted from `Place` in T-106.
 *
 * A curator hand-setting a field in Filament claims it: enrichment, geocode
 * refresh and re-resolve must all leave it alone thereafter. That is a policy
 * about *who owns a value*, not about how a place persists — which is why it
 * reads better here than inline on a model that already carried five unrelated
 * concerns.
 *
 * The using model supplies a `locked_fields` array column.
 */
trait LocksFields
{
    /**
     * Curated business fields a human may hand-set (T-084) and thereby lock. The
     * enricher and re-share resolve backfills only ever touch a field in this set
     * when it is NOT in {@see lockedFields()} — a manual override always wins.
     *
     * @var list<string>
     */
    public const CURATED_FIELDS = [
        'name', 'address_line1', 'address_line2', 'city', 'region', 'postal_code',
        'country_code', 'cuisine_primary', 'price_range', 'phone', 'website',
        'image_url', 'thumbnail_url', 'gallery_json', 'opening_hours_json',
        // T-155. Not curated in the sense of "a human types these" — nobody
        // hand-writes an IANA zone or a period list; they edit the
        // `opening_hours_json` LINES. They are here because this list is what
        // `PlaceEditor::apply()` will write AT ALL, and the enricher is the only
        // thing that fills them. Omitting them made both columns unwritable in
        // production while every test passed, because the tests wrote them
        // through the factory and never through the editor.
        //
        // They stay out of `PlaceEditSuggestion::FIELDS` deliberately: that list
        // is what a member of the public may propose, and a proposed timezone is
        // a proposed change to whether a venue looks open.
        'opening_hours_periods_json', 'timezone',
    ];

    /**
     * Curated fields a human has hand-set and thereby locked. Always a list of
     * {@see CURATED_FIELDS} names; an unset/legacy row reads as empty.
     *
     * @return list<string>
     */
    public function lockedFields(): array
    {
        $locked = $this->locked_fields;

        return is_array($locked) ? array_values(array_intersect(self::CURATED_FIELDS, $locked)) : [];
    }

    /** Whether a human owns this field, so enrichment/resolve must not touch it. */
    public function isFieldLocked(string $field): bool
    {
        return in_array($field, $this->lockedFields(), true);
    }

    /**
     * Mark the given curated fields as human-owned — merged into the existing
     * set, deduped, and confined to {@see CURATED_FIELDS}. Stages the attribute
     * only; the caller persists. Unknown field names are ignored.
     *
     * @param  iterable<string>  $fields
     */
    public function lockFields(iterable $fields): void
    {
        $merged = array_unique([...$this->lockedFields(), ...$fields]);
        $this->locked_fields = array_values(array_intersect(self::CURATED_FIELDS, $merged));
    }

    /**
     * Drop any locked (human-owned) keys from an enrichment/backfill patch so a
     * manual override survives. Non-curated keys pass through untouched.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function withoutLockedFields(array $patch): array
    {
        return array_filter(
            $patch,
            fn (string $field) => ! $this->isFieldLocked($field),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
