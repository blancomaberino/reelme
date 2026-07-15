<?php

use App\Enums\MediaKind;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\Media\Images\PostImageIngestor;
use App\Services\Media\Images\PostImageResolver;
use App\Services\Media\RemoteFileFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const IMG_DISK = 'local_media';

beforeEach(function () {
    Storage::fake(IMG_DISK);
});

/** A resolver that returns a fixed list — lets us drive the ingestor directly. */
function fixedResolver(array $urls): PostImageResolver
{
    return new class($urls) implements PostImageResolver
    {
        public function __construct(private array $urls) {}

        public function resolve(SourcePost $post): array
        {
            return $this->urls;
        }
    };
}

function ingestorWith(PostImageResolver $resolver): PostImageIngestor
{
    return new PostImageIngestor([$resolver], app(RemoteFileFetcher::class));
}

/** 1x1 PNG bytes — passes getimagesize(). */
function pngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
}

it('stores each resolved image as a keyframe in order', function () {
    // Distinct bytes per URL so both slides are stored (dedupe is by content).
    Http::fake(fn ($request) => Http::response(pngBytes().$request->url(), 200, ['Content-Type' => 'image/png']));
    $share = Share::factory()->create();

    $stored = ingestorWith(fixedResolver([
        'https://cdn.example.com/1.jpg',
        'https://cdn.example.com/2.jpg',
    ]))->ingest($share, $share->sourcePost);

    expect($stored)->toBe(2);
    $frames = $share->sourcePost->mediaAssets()->where('kind', MediaKind::Keyframe->value)->orderBy('frame_at_ms')->get();
    expect($frames)->toHaveCount(2)
        ->and($frames->pluck('frame_at_ms')->all())->toBe([0, 1000]);
});

it('caps ingestion at 12 images', function () {
    // Distinct bytes per URL (a real carousel) → distinct sha256, so all are
    // stored up to the cap; getimagesize ignores the trailing marker.
    Http::fake(fn ($request) => Http::response(pngBytes().$request->url(), 200, ['Content-Type' => 'image/png']));
    $share = Share::factory()->create();
    $urls = array_map(fn (int $i) => "https://cdn.example.com/{$i}.jpg", range(1, 20));

    $stored = ingestorWith(fixedResolver($urls))->ingest($share, $share->sourcePost);

    expect($stored)->toBe(12)
        ->and($share->sourcePost->mediaAssets()->where('kind', MediaKind::Keyframe->value)->count())->toBe(12);
});

it('skips a url whose body is not a decodable image', function () {
    Http::fake([
        'https://cdn.example.com/bad*' => Http::response('not an image', 200),
        'https://cdn.example.com/*' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    $share = Share::factory()->create();

    $stored = ingestorWith(fixedResolver([
        'https://cdn.example.com/bad.jpg',
        'https://cdn.example.com/good.jpg',
    ]))->ingest($share, $share->sourcePost);

    expect($stored)->toBe(1);
});

it('returns zero when no resolver produces urls', function () {
    $share = Share::factory()->create();

    $stored = ingestorWith(fixedResolver([]))->ingest($share, $share->sourcePost);

    expect($stored)->toBe(0)
        ->and($share->sourcePost->mediaAssets()->count())->toBe(0);
});

it('does not duplicate on identical bytes (sha256 dedupe)', function () {
    Http::fake([
        'https://cdn.example.com/*' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    $share = Share::factory()->create();

    // Same bytes at two URLs → one media_asset row (keyed on sha256 + post); the
    // duplicate is skipped before writing, so it is not counted as stored.
    $stored = ingestorWith(fixedResolver([
        'https://cdn.example.com/a.jpg',
        'https://cdn.example.com/b.jpg',
    ]))->ingest($share, $share->sourcePost);

    expect($stored)->toBe(1)
        ->and($share->sourcePost->mediaAssets()->where('kind', MediaKind::Keyframe->value)->count())->toBe(1);
});
