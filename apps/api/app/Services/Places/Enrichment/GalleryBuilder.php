<?php

namespace App\Services\Places\Enrichment;

use App\Models\Place;
use Illuminate\Support\Str;

/**
 * Merges the per-source gallery contributions into one ordered, deduped,
 * capped `gallery_json` (T-099). Ordering encodes the owner's "prefer photos
 * that actually belong to the business" rule:
 *
 *   1. website (schema.org) images — definitively owned;
 *   2. Google photos whose attribution matches the business (name or website
 *      domain) — best-effort "owned" (legacy Places API has no owner flag, so
 *      this is a heuristic on `html_attributions`);
 *   3. remaining Google photos — fill;
 *   4. the reel-derived thumbnail — last-resort so a place with no crawl keeps ≥1.
 *
 * The sort is stable (rank, then original order), so within a tier the sources'
 * own ordering is preserved. Dedup is by normalized URL; the result is capped at
 * `places.enrich.gallery.max_images`.
 */
class GalleryBuilder
{
    private const RANK_WEBSITE = 0;

    private const RANK_GOOGLE_OWNED = 1;

    private const RANK_GOOGLE_OTHER = 2;

    private const RANK_REEL = 3;

    /**
     * @param  list<array{url?: mixed, source?: mixed, attribution?: mixed}>  $entries  concatenated per-source contributions
     * @param  ?string  $pinnedFirst  a human-locked hero URL that must stay gallery[0] (keeps the contract's "image_url mirrors gallery[0]" true when only image_url is locked)
     * @return list<array{url: string, source: string, attribution: ?string}>
     */
    public function build(Place $place, array $entries, ?string $reelFallback = null, ?string $pinnedFirst = null): array
    {
        // A locked hero is a candidate even if no source emitted it this run, and
        // ranks ahead of everything so it stays gallery[0].
        $pinnedKey = null;
        if ($pinnedFirst !== null && preg_match('#^https?://#i', trim($pinnedFirst)) === 1) {
            $pinnedFirst = trim($pinnedFirst);
            $pinnedKey = $this->normalizeUrl($pinnedFirst);
            array_unshift($entries, ['url' => $pinnedFirst, 'source' => 'website', 'attribution' => null]);
        }

        if ($reelFallback !== null && trim($reelFallback) !== '') {
            $entries[] = ['url' => trim($reelFallback), 'source' => 'reel', 'attribution' => null];
        }

        $needles = $this->needles($place);

        // Decorate with (rank, original index, dedup key) for a stable priority
        // sort; the normalized key is computed once here and reused for dedup.
        $decorated = [];
        foreach ($entries as $index => $entry) {
            $normalized = $this->normalizeEntry($entry);
            if ($normalized === null) {
                continue;
            }
            $key = $this->normalizeUrl($normalized['url']);
            $decorated[] = [
                'rank' => $pinnedKey !== null && $key === $pinnedKey
                    ? -1 // the locked hero outranks every source tier
                    : $this->rank($normalized, $needles),
                'index' => $index,
                'key' => $key,
                'entry' => $normalized,
            ];
        }

        usort($decorated, fn (array $a, array $b): int => [$a['rank'], $a['index']] <=> [$b['rank'], $b['index']]);

        $max = max(1, (int) config('places.enrich.gallery.max_images', 8));
        $seen = [];
        $gallery = [];
        foreach ($decorated as $item) {
            if (isset($seen[$item['key']])) {
                continue;
            }
            $seen[$item['key']] = true;
            $gallery[] = $item['entry'];
            if (count($gallery) >= $max) {
                break;
            }
        }

        return $gallery;
    }

    /**
     * @param  array{url?: mixed, source?: mixed, attribution?: mixed}  $entry
     * @return array{url: string, source: string, attribution: ?string}|null
     */
    private function normalizeEntry(array $entry): ?array
    {
        $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : '';
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null; // only client-loadable http(s) URLs survive
        }

        $source = is_string($entry['source'] ?? null) ? $entry['source'] : 'website';
        $attribution = is_string($entry['attribution'] ?? null) && trim($entry['attribution']) !== ''
            ? trim($entry['attribution'])
            : null;

        return ['url' => $url, 'source' => $source, 'attribution' => $attribution];
    }

    /**
     * @param  array{url: string, source: string, attribution: ?string}  $entry
     * @param  list<string>  $needles
     */
    private function rank(array $entry, array $needles): int
    {
        return match ($entry['source']) {
            'website' => self::RANK_WEBSITE,
            'reel' => self::RANK_REEL,
            'google' => $this->isBusinessAttributed($entry['attribution'], $needles)
                ? self::RANK_GOOGLE_OWNED
                : self::RANK_GOOGLE_OTHER,
            default => self::RANK_GOOGLE_OTHER,
        };
    }

    /**
     * The business's identifying needles — its normalized name and its website
     * domain — used to spot a Google photo whose attribution names the business.
     *
     * @return list<string>
     */
    private function needles(Place $place): array
    {
        $needles = [];
        $name = $this->fold((string) $place->name);
        if (mb_strlen($name) >= 3) {
            $needles[] = $name;
        }

        $host = strtolower((string) parse_url((string) $place->website, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        // The registrable-ish label (drop the TLD) — "joes" from "joes.com", folded
        // so a hyphenated domain ("my-cafe.com" → "mycafe") still matches an
        // attribution's folded form.
        $label = $this->fold($host !== '' ? explode('.', $host)[0] : '');
        if (mb_strlen($label) >= 3) {
            $needles[] = $label;
        }

        return $needles;
    }

    /**
     * @param  list<string>  $needles
     */
    private function isBusinessAttributed(?string $attribution, array $needles): bool
    {
        if ($attribution === null || $needles === []) {
            return false;
        }
        $folded = $this->fold($attribution);
        foreach ($needles as $needle) {
            if (str_contains($folded, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercased, accent-folded, alphanumeric-only — for a forgiving name match. */
    private function fold(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii($value))) ?? '';
    }

    /**
     * Normalize a URL for dedup — scheme- and trailing-slash-insensitive, host
     * lowercased. The PATH keeps its case: CDN/photo paths are case-sensitive, so
     * folding it could collapse two genuinely different images into one.
     */
    private function normalizeUrl(string $url): string
    {
        $noScheme = preg_replace('#^https?://#i', '', $url) ?? $url;
        $slash = strpos($noScheme, '/');
        if ($slash === false) {
            return rtrim(strtolower($noScheme), '/');
        }

        return rtrim(strtolower(substr($noScheme, 0, $slash)).substr($noScheme, $slash), '/');
    }
}
