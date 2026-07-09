<?php

namespace App\Adapters;

use App\Adapters\Data\FetchedMedia;
use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\NeedsManualFallback;
use App\Enums\MediaKind;
use App\Models\MediaAsset;
use App\Models\SourcePost;
use App\Services\Media\MediaUrlService;

/**
 * The guaranteed terminal adapter (ADR-011: ingestion never dead-ends). Passive
 * and side-effect free — the state flip to `review` is the calling job's job.
 *
 * A "manual payload" is a user-pasted caption on the source_post plus an
 * uploaded `screen_recording` media_asset (via T-009's presigned upload). With
 * no payload yet, fetchMetadata throws NeedsManualFallback so the app prompts.
 *
 * NOTE: `source_post` is a shared reference entity with no user scope — this
 * adapter has no user context and will return whatever recording is attached to
 * a URL. The CALLER (T-016) must authorize the user before invoking the chain.
 */
class ManualUploadAdapter implements SourceAdapter
{
    public function __construct(private readonly MediaUrlService $mediaUrls) {}

    public function supports(string $canonicalUrl): bool
    {
        return true;
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        $post = $this->postByUrl($canonicalUrl);

        if ($post === null || $this->screenRecording($post) === null) {
            throw new NeedsManualFallback("No manual payload yet for [{$canonicalUrl}].");
        }

        return new SourcePostData(
            platform: $post->platform,
            externalId: $post->external_id,
            url: $post->url,
            caption: $post->caption,
            raw: ['source' => 'manual'],
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        $model = $this->postByUrl($post->url);
        $asset = $model !== null ? $this->screenRecording($model) : null;

        if ($asset === null) {
            return new MediaFetchResult;
        }

        // Already on the media disk — hand back a short-lived URL to it.
        return new MediaFetchResult([
            new FetchedMedia(
                kind: MediaKind::ScreenRecording,
                url: $this->mediaUrls->temporaryUrl($asset->storage_path, $asset->disk),
                mime: $asset->mime,
            ),
        ]);
    }

    private function postByUrl(string $canonicalUrl): ?SourcePost
    {
        return SourcePost::query()->where('url', $canonicalUrl)->first();
    }

    private function screenRecording(SourcePost $post): ?MediaAsset
    {
        return $post->mediaAssets()
            ->where('kind', MediaKind::ScreenRecording)
            ->first();
    }
}
