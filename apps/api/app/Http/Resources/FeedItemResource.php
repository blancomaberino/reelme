<?php

namespace App\Http\Resources;

use App\Enums\MediaKind;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Services\Media\MediaUrlService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One feed entry (T-034, 03 §2.8): a published share with its sharer,
 * source-post link-out, crediting influencer, and the place it published.
 * Callers must eager-load `user`, `sourcePost.influencer`,
 * `sourcePost.mediaAssets` and `publishedPlaceSource.place` (with lat/lng
 * aliases on the place — see FeedController).
 *
 * @mixin Share
 */
class FeedItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $post = $this->sourcePost;
        $sharer = $this->user;
        $place = $this->publishedPlaceSource?->place;

        return [
            'id' => (string) $this->id,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'sharer' => ($sharer === null || ! $sharer->is_public)
                ? null
                : new UserSummaryResource($sharer),
            'source_post' => $post === null ? null : [
                'platform' => $post->platform->value,
                'url' => $post->url,
                'caption' => $post->caption === null ? null : Str::limit($post->caption, 200),
                'thumbnail_url' => $this->thumbnailUrl(),
            ],
            'influencer' => $post?->influencer === null
                ? null
                : new InfluencerSummaryResource($post->influencer),
            'place' => $place === null ? null : new PlaceSummaryResource($place),
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
}
