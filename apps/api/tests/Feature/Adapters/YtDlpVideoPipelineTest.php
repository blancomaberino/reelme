<?php

use App\Adapters\AdapterRegistry;
use App\Adapters\OEmbedAdapter;
use App\Adapters\YtDlpAdapter;
use App\Enums\MediaKind;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Jobs\DownloadMedia;
use App\Jobs\PrepareMedia;
use App\Models\Share;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\Images\PostImageIngestor;
use App\Services\Media\MediaProcessor;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

// Feature dir auto-binds Tests\TestCase + RefreshDatabase (see tests/Pest.php).

beforeEach(function () {
    Storage::fake('local_media_originals');
    Storage::fake('local_media');
    // The real chain: oEmbed (caption) → yt-dlp (video). oEmbed yields no media,
    // so DownloadMedia advances to the yt-dlp adapter for the real video.
    config()->set('ingestion.chains.instagram', [OEmbedAdapter::class, YtDlpAdapter::class]);
    // yt-dlp is disabled in the base test env — opt in (Process is faked below).
    config()->set('ingestion.ytdlp.enabled', true);
    app()->forgetInstance(AdapterRegistry::class);
});

/** A fetching Instagram reel share. */
function reelShare(): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update([
        'platform' => Platform::Instagram,
        'url' => 'https://www.instagram.com/reel/ABC123/',
        'caption' => 'best tacos in lisbon',
    ]);

    return $share;
}

it('a video reel resolved via yt-dlp reaches real ffmpeg keyframes (not caption-only)', function () {
    // yt-dlp "downloads" the reel: fake its success and hand back a real video
    // temp file (a copy of the fixture — the adapter's localPath is consumed and
    // unlinked by DownloadMedia, so it must not be the committed fixture).
    $video = (string) tempnam(sys_get_temp_dir(), 'reel_').'.mp4';
    copy(base_path('tests/Fixtures/media/sample.mp4'), $video);
    // Scope the fake to yt-dlp ONLY — PrepareMedia's real ffmpeg/ffprobe must
    // still run (a blanket '*' fake would stub them and produce no frames). The
    // `*yt-dlp*` contains-match is required: Laravel quotes each array arg, so an
    // anchored `yt-dlp*` never matches the stringified command.
    Process::fake(['*yt-dlp*' => Process::result(output: $video."\n")]);

    $share = reelShare();

    // Stage 1: DownloadMedia stores the yt-dlp video as the Video original.
    (new DownloadMedia($share->id))->handle(app(AdapterRegistry::class), app(FfmpegRunner::class));

    $original = $share->sourcePost->mediaAssets()->where('kind', MediaKind::Video->value)->first();
    expect($original)->not->toBeNull()
        ->and($original->bytes)->toBe(filesize(base_path('tests/Fixtures/media/sample.mp4')));
    // yt-dlp really ran (faked) with the reel URL.
    Process::assertRan(fn ($p) => end($p->command) === 'https://www.instagram.com/reel/ABC123/');
    // The consumed temp video is cleaned up.
    expect(file_exists($video))->toBeFalse();

    // Stage 2: PrepareMedia extracts scene keyframes + poster from that original.
    (new PrepareMedia($share->id))->handle(app(MediaProcessor::class), app(FfmpegRunner::class), app(PostImageIngestor::class));

    $keyframes = $share->sourcePost->mediaAssets()->where('kind', MediaKind::Keyframe->value)->count();
    $poster = $share->sourcePost->mediaAssets()->where('kind', MediaKind::Thumbnail->value)->count();
    expect($keyframes)->toBeGreaterThan(0)
        ->and($poster)->toBe(1);
})->group('ffmpeg');

it('an image post (no video formats) leaves no video original — falls through cleanly', function () {
    Process::fake(['*yt-dlp*' => Process::result(output: '', errorOutput: 'ERROR: No video formats found!', exitCode: 1)]);

    $share = reelShare();

    (new DownloadMedia($share->id))->handle(app(AdapterRegistry::class), app(FfmpegRunner::class));

    // yt-dlp really was attempted (faked) for the reel before falling through.
    Process::assertRan(fn ($p) => end($p->command) === 'https://www.instagram.com/reel/ABC123/');
    // No video stored; the share stays fetching for the image-resolver path.
    expect($share->sourcePost->mediaAssets()->where('kind', MediaKind::Video->value)->count())->toBe(0)
        ->and($share->fresh()->status)->toBe(ShareStatus::Fetching);
});
