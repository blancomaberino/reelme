<?php

namespace App\Services\Reviews;

use Illuminate\Support\Carbon;

/**
 * A single review source's contribution to a place's aggregated rating (T-082):
 * `{ source, rating, count, url, synced_at, snippets[] }`. One of these per
 * provider that resolves for the place — Google, native Reelmap, Trustpilot, … —
 * assembled by {@see ReviewSourceRegistry} and rendered as a per-source summary
 * row on the place detail. `rating` is normalized to a 0–5 scale; `url` deep
 * links to the full reviews on that source (null for the intrinsic native
 * source); `syncedAt` is when the cached external content was last fetched.
 */
final readonly class ReviewSourceSummary
{
    /**
     * @param  list<ReviewSnippet>  $snippets
     */
    public function __construct(
        public string $source,
        public ?float $rating,
        public int $count,
        public ?string $url = null,
        public ?Carbon $syncedAt = null,
        public array $snippets = [],
    ) {}

    /**
     * @return array{source: string, rating: float|null, count: int, url: string|null, synced_at: string|null, snippets: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'rating' => $this->rating !== null ? round($this->rating, 1) : null,
            'count' => $this->count,
            'url' => $this->url,
            'synced_at' => $this->syncedAt?->toIso8601ZuluString(),
            'snippets' => array_map(fn (ReviewSnippet $s): array => $s->toArray(), $this->snippets),
        ];
    }
}
