<?php

namespace Tests\Support;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\SourceAdapter;
use App\Enums\Platform;

/** A successful Instagram adapter for pipeline tests (no network). */
class FakeInstagramAdapter implements SourceAdapter
{
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
        return new SourcePostData(
            platform: Platform::Instagram,
            externalId: 'FAKE1',
            url: $canonicalUrl,
            caption: 'best noodles in lisbon',
            authorHandle: '@noodle.hunter',
            authorDisplayName: 'Noodle Hunter',
            raw: ['source' => 'fake'],
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        return new MediaFetchResult;
    }
}
