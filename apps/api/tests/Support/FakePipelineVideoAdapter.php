<?php

namespace Tests\Support;

use App\Adapters\Data\FetchedMedia;
use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\SourceAdapter;
use App\Enums\MediaKind;
use App\Enums\Platform;

/**
 * A fully-successful Instagram adapter that ALSO yields a real video — the media
 * boundary for the T-028 end-to-end pipeline suite. Unlike FakeInstagramAdapter
 * (metadata only), fetchMedia() hands back a local copy of the committed sample
 * video so DownloadMedia → PrepareMedia → TranscribeAudio exercise real ffmpeg on
 * a genuine file, with zero network.
 *
 * Each fetchMedia() copies the fixture to a fresh temp file because DownloadMedia
 * treats a `localPath` as a consumable temp original and unlinks it after ingest
 * (handing it the committed fixture would delete the fixture).
 */
class FakePipelineVideoAdapter implements SourceAdapter
{
    public function __construct(private readonly string $fixture = 'sample.mp4') {}

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
            externalId: 'FAKEVID1',
            url: $canonicalUrl,
            caption: 'Hand-pulled noodles in Chinatown 🍜 best beef noodle soup',
            authorHandle: '@londonbites',
            authorDisplayName: 'London Bites',
            raw: ['source' => 'fake-video'],
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pipevid_').'.mp4';
        copy(base_path("tests/Fixtures/media/{$this->fixture}"), $tmp);

        return new MediaFetchResult([
            new FetchedMedia(kind: MediaKind::Video, localPath: $tmp, mime: 'video/mp4'),
        ]);
    }
}
