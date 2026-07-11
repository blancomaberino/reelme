<?php

use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Jobs\TranscribeAudio;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\Exceptions\TranscriptionFailed;
use App\Services\Transcription\HostedTranscriber;
use App\Services\Transcription\TranscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTranscriber;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local_media');
});

/** A fetching share whose source_post has an audio WAV asset on the media disk. */
function shareWithAudio(): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $path = "media/{$share->id}/audio.wav";
    Storage::disk('local_media')->put($path, 'RIFF....wav');
    MediaAsset::create([
        'source_post_id' => $share->sourcePost->id,
        'kind' => MediaKind::Audio,
        'storage_path' => $path,
        'disk' => 'local_media',
        'mime' => 'audio/wav',
        'bytes' => 11,
        'sha256' => hash('sha256', 'RIFF....wav'),
        'duration_ms' => 5000,
    ]);

    return $share;
}

function bindManager(FakeTranscriber $primary): void
{
    app()->instance(TranscriptionManager::class, new TranscriptionManager($primary, app(HostedTranscriber::class)));
}

it('persists a transcript with language, text, and segments', function () {
    $share = shareWithAudio();
    $fake = new FakeTranscriber(result: new TranscriptionResult(
        language: 'pt',
        text: 'Melhor restaurante',
        segments: [['start_ms' => 0, 'end_ms' => 2500, 'text' => 'Melhor restaurante']],
        driver: 'whisper_cpp',
    ));
    bindManager($fake);

    (new TranscribeAudio($share->id))->handle(app(TranscriptionManager::class));

    $t = $share->sourcePost->fresh()->transcript_json;
    expect($t['language'])->toBe('pt')
        ->and($t['text'])->toBe('Melhor restaurante')
        ->and($t['segments'])->toHaveCount(1)
        ->and($t['empty'])->toBeFalse()
        ->and($fake->transcribed)->toBeTrue();
});

it('stores an empty transcript for a silent video and does not fail', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]); // no audio asset
    $fake = new FakeTranscriber;
    bindManager($fake);

    (new TranscribeAudio($share->id))->handle(app(TranscriptionManager::class));

    $t = $share->sourcePost->fresh()->transcript_json;
    expect($t['empty'])->toBeTrue()
        ->and($t['text'])->toBe('')
        ->and($t['segments'])->toBe([])
        ->and($fake->transcribed)->toBeFalse()          // transcriber never invoked
        ->and($share->fresh()->status)->toBe(ShareStatus::Fetching); // chain proceeds
});

it('is idempotent — an already-transcribed post is not re-transcribed', function () {
    $share = shareWithAudio();
    $share->sourcePost->forceFill(['transcript_json' => ['language' => 'en', 'text' => 'existing', 'segments' => [], 'driver' => 'whisper_cpp', 'empty' => false]])->save();
    $fake = new FakeTranscriber;
    bindManager($fake);

    (new TranscribeAudio($share->id))->handle(app(TranscriptionManager::class));

    expect($fake->transcribed)->toBeFalse()
        ->and($share->sourcePost->fresh()->transcript_json['text'])->toBe('existing');
});

it('skips when the share is not in the fetching state', function () {
    $share = Share::factory()->published()->create();
    $fake = new FakeTranscriber;
    bindManager($fake);

    (new TranscribeAudio($share->id))->handle(app(TranscriptionManager::class));

    expect($share->sourcePost->fresh()->transcript_json)->toBeNull()
        ->and($fake->transcribed)->toBeFalse();
});

it('maps a total transcription failure to transcribe_error', function () {
    $share = shareWithAudio();
    config()->set('transcription.hosted.enabled', false);
    bindManager(new FakeTranscriber(available: true, throws: true));

    expect(fn () => (new TranscribeAudio($share->id))->handle(app(TranscriptionManager::class)))
        ->toThrow(TranscriptionFailed::class);

    // The failed() hook maps the exhausted job to the taxonomy code.
    (new TranscribeAudio($share->id))->failed(new TranscriptionFailed('x'));
    expect($share->fresh()->status)->toBe(ShareStatus::Failed)
        ->and($share->fresh()->failure_reason)->toBe('transcribe_error');
});
