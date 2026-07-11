<?php

namespace App\Http\Resources;

use App\Enums\MediaKind;
use App\Models\MediaAsset;
use App\Models\PlaceSource;
use App\Services\Media\MediaUrlService;
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
                'thumbnail_url' => $this->thumbnailUrl(),
            ],
            'influencer' => $post?->influencer === null
                ? null
                : new InfluencerSummaryResource($post->influencer),
            // A private sharer is withheld entirely — attribution is public content.
            'sharer' => ($sharer === null || ! $sharer->is_public)
                ? null
                : new UserSummaryResource($sharer),
            'highlights' => [
                'dishes' => $this->dishNames($snapshot),
                'tags' => $this->snapshotTags($snapshot),
            ],
        ];
    }

    /** Signed URL for the source post's thumbnail asset, when one exists. */
    private function thumbnailUrl(): ?string
    {
        /** @var MediaAsset|null $thumb */
        $thumb = $this->sourcePost?->mediaAssets
            ->first(fn (MediaAsset $a) => $a->kind === MediaKind::Thumbnail);

        if ($thumb === null) {
            return null;
        }

        return app(MediaUrlService::class)->temporaryUrl($thumb->storage_path, $thumb->disk);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function dishNames(array $snapshot): array
    {
        if (! is_array($snapshot['dishes'] ?? null)) {
            return [];
        }

        $names = [];
        foreach ($snapshot['dishes'] as $dish) {
            $name = is_array($dish) ? trim((string) ($dish['name'] ?? '')) : '';
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        return array_values($names);
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
