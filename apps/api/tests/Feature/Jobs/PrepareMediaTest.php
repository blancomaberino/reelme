<?php

use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Jobs\PrepareMedia;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\MediaProcessingException;
use App\Services\Media\MediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Every test here executes the real ffmpeg/ffprobe binaries.
pest()->group('ffmpeg');

const ORIGINALS = 'local_media_originals';
const DERIVED = 'local_media';

beforeEach(function () {
    Storage::fake(ORIGINALS);
    Storage::fake(DERIVED);
});

function mediaFixture(string $file): string
{
    return base_path("tests/Fixtures/media/{$file}");
}

/** Seed a fetching share whose source_post has an original video on the disk. */
function shareWithOriginal(string $fixture = 'sample.mp4'): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $bytes = (string) file_get_contents(mediaFixture($fixture));
    $path = "media/{$share->id}/original/orig.mp4";
    Storage::disk(ORIGINALS)->put($path, $bytes);

    MediaAsset::create([
        'source_post_id' => $share->sourcePost->id,
        'kind' => MediaKind::Video,
        'storage_path' => $path,
        'disk' => ORIGINALS,
        'mime' => 'video/mp4',
        'bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'duration_ms' => 5000,
        'width' => 320,
        'height' => 240,
    ]);

    return $share;
}

function runPrepare(int $shareId): void
{
    (new PrepareMedia($shareId))->handle(app(MediaProcessor::class), app(FfmpegRunner::class));
}

/** ffprobe a stored derivative (sample_rate + channels). */
function probeStored(string $disk, string $path): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'probe_').'.bin';
    file_put_contents($tmp, Storage::disk($disk)->get($path));
    $result = Process::run(['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_streams', $tmp]);
    @unlink($tmp);
    $json = json_decode($result->output(), true) ?: [];

    return $json['streams'][0] ?? [];
}

it('extracts a 16 kHz mono WAV, keyframes, and a thumbnail as media_assets', function () {
    $share = shareWithOriginal('sample.mp4');

    runPrepare($share->id);

    $assets = $share->sourcePost->mediaAssets()->get()->groupBy(fn ($a) => $a->kind->value);

    // Audio: exactly one WAV, 16 kHz mono.
    expect($assets['audio'])->toHaveCount(1);
    $audio = $assets['audio']->first();
    $stream = probeStored($audio->disk, $audio->storage_path);
    expect($stream['sample_rate'] ?? null)->toBe('16000')
        ->and($stream['channels'] ?? null)->toBe(1);

    // Keyframes: 1..12, chronological with strictly increasing frame_at_ms.
    $frames = $assets['keyframe']->sortBy('id')->values();
    expect($frames->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(12);
    $times = $frames->pluck('frame_at_ms')->all();
    expect($times)->toBe(array_values(array_unique($times)))       // no dupes
        ->and($times)->toBe(collect($times)->sort()->values()->all()); // ascending
    $frames->each(fn ($f) => expect($f->width)->toBeGreaterThan(0)->and($f->height)->toBeGreaterThan(0));

    // Thumbnail: exactly one.
    expect($assets['thumbnail'])->toHaveCount(1);
});

it('produces no audio asset for a silent video and does not fail', function () {
    $share = shareWithOriginal('sample_noaudio.mp4');

    runPrepare($share->id);

    expect($share->sourcePost->mediaAssets()->where('kind', MediaKind::Audio->value)->count())->toBe(0)
        ->and($share->sourcePost->mediaAssets()->where('kind', MediaKind::Keyframe->value)->count())->toBeGreaterThan(0)
        ->and($share->fresh()->status)->toBe(ShareStatus::Fetching); // no failure
});

it('is idempotent — a re-run adds no duplicate rows', function () {
    $share = shareWithOriginal('sample.mp4');

    runPrepare($share->id);
    $before = $share->sourcePost->mediaAssets()->count();
    runPrepare($share->id);

    expect($share->sourcePost->mediaAssets()->count())->toBe($before);
});

it('throws MediaProcessingException on corrupt input (mapped to ffmpeg_error)', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $path = "media/{$share->id}/original/orig.mp4";
    Storage::disk(ORIGINALS)->put($path, 'this is not a video');
    MediaAsset::create([
        'source_post_id' => $share->sourcePost->id,
        'kind' => MediaKind::Video,
        'storage_path' => $path,
        'disk' => ORIGINALS,
        'mime' => 'video/mp4',
        'bytes' => 19,
        'sha256' => hash('sha256', 'this is not a video'),
    ]);

    expect(fn () => runPrepare($share->id))->toThrow(MediaProcessingException::class);

    // The failed() hook maps the exhausted job to the ffmpeg_error taxonomy code.
    (new PrepareMedia($share->id))->failed(new MediaProcessingException('boom'));
    expect($share->fresh()->status)->toBe(ShareStatus::Failed)
        ->and($share->fresh()->failure_reason)->toBe('ffmpeg_error');
});
