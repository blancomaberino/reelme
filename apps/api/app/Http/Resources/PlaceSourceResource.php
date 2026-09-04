<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesThumbnail;
use App\Models\PlaceSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One place_source row — the attribution unit (T-030, 03 §2.6): the original
 * post link-out, the crediting influencer, the sharer (only when they are
 * public), and the frozen extraction highlights.
 *
 * Callers must eager-load `sourcePost.influencer`, `sourcePost.mediaAssets`
 * (thumbnails) and `share.user`.
 *
 * @mixin PlaceSource
 */
class PlaceSourceResource extends JsonResource
{
    use ResolvesThumbnail;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $post = $this->sourcePost;
        $sharer = $this->share?->user;
        $snapshot = $this->extraction_snapshot_json;

        return [
            'id' => (string) $this->id,
            'is_primary' => (bool) $this->is_primary,
            'source_post' => $post === null ? null : [
                'platform' => $post->platform->value,
                'url' => $post->url,
                'caption' => $post->caption === null ? null : Str::limit($post->caption, 200),
                'posted_at' => $post->posted_at?->toIso8601ZuluString(),
                'thumbnail_url' => $this->resolveThumbnail($post),
            ],
            'influencer' => $post?->influencer === null
                ? null
                : new InfluencerSummaryResource($post->influencer),
            // A private sharer is withheld entirely — attribution is public content.
            'sharer' => ($sharer === null || ! $sharer->is_public)
                ? null
                : new UserSummaryResource($sharer),
            'highlights' => [
                'dishes' => $this->dishNames(),
                'tags' => $this->snapshotTags($snapshot),
            ],
        ];
    }

    /**
     * The source's dish names, from the first-class `dishes` rows rather than a
     * second parse of the snapshot (T-157). One dish corpus: what this lists,
     * what the place aggregate lists, and what `?dish=` matches are the same
     * rows, so they cannot answer differently.
     *
     * @return list<string>
     */
    private function dishNames(): array
    {
        // No `unique()`: `unique(place_source_id, name)` already makes a
        // duplicate impossible, and keeping the call would imply a case that
        // cannot occur.
        return $this->dishes->pluck('name')->all();
    }

    /**
     * Union of the snapshot's cuisine/vibe/dietary tag lists.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function snapshotTags(array $snapshot): array
    {
        $tags = [];
        foreach (['cuisines', 'vibe_tags', 'dietary_tags'] as $key) {
            if (! is_array($snapshot[$key] ?? null)) {
                continue;
            }
            foreach ($snapshot[$key] as $tag) {
                if (is_scalar($tag) && trim((string) $tag) !== '') {
                    $tags[trim((string) $tag)] = trim((string) $tag);
                }
            }
        }

        return array_values($tags);
    }
}
