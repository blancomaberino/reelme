<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * The derived matching/URL columns (T-106): `normalized_name` for trigram
 * matching and `slug` for stable public URLs, both maintained on save — the
 * "observer" of 02 §3.8.
 *
 * DEVIATION from the T-106 brief, which grouped `normalizeName()` with the
 * PostGIS pair in `HasGeoPoint`. Name normalization is not geography: it feeds
 * the trigram dedup scan, and it belongs with `makeSlug()` and the `saving`
 * hook that actually maintains both columns. Splitting it out keeps
 * {@see HasGeoPoint} honestly about points. Recorded in ADR-106.
 */
trait DerivesNameColumns
{
    /**
     * Maintain the derived columns on every save. Eloquent boots a trait's
     * `boot<TraitName>` automatically, so the model needs no `booted()` of its
     * own for this.
     */
    public static function bootDerivesNameColumns(): void
    {
        static::saving(function (self $model): void {
            $attributes = $model->getAttributes();
            $name = (string) $model->name;

            if ($model->isDirty('name') || ! array_key_exists('normalized_name', $attributes)) {
                $model->normalized_name = self::normalizeName($name);
            }
            if ($name !== '' && ($attributes['slug'] ?? '') === '') {
                $model->slug = self::makeSlug($name);
            }
        });
    }

    /** Lowercase, accent-fold, drop punctuation and trailing legal suffixes. */
    public static function normalizeName(string $name): string
    {
        $value = Str::of($name)->ascii()->lower()->toString();
        $value = (string) preg_replace('/[^a-z0-9\s]/', ' ', $value);
        // Strip common company/legal suffixes so "Joe's Ltd" ≈ "Joe's".
        $value = (string) preg_replace('/\b(ltd|limited|inc|incorporated|llc|llp|co|corp|gmbh|sa|srl|bv|plc|pty)\b/', ' ', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /** Globally-unique slug: name stem + short random suffix. */
    public static function makeSlug(string $name): string
    {
        $stem = Str::slug($name) ?: 'place';

        return Str::limit($stem, 260, '').'-'.Str::lower(Str::random(6));
    }
}
