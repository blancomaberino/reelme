<?php

use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\Exceptions\TranscriptionFailed;
use App\Services\Transcription\HostedTranscriber;
use App\Services\Transcription\TranscriptionManager;
use App\Services\Transcription\WhisperCppTranscriber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeTranscriber;
use Tests\TestCase;

uses(TestCase::class);

function transcriptionFixture(string $file): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/transcription/{$file}"));
}

function tempWav(): string
{
    $wav = (string) tempnam(sys_get_temp_dir(), 'twav_');
    file_put_contents($wav, 'RIFFfake-wav-bytes');

    return $wav;
}

it('parses whisper.cpp JSON output into language + segments', function () {
    $wav = tempWav();
    file_put_contents($wav.'.json', transcriptionFixture('whisper_output.json'));
    Process::fake();

    $result = (new WhisperCppTranscriber)->transcribe($wav);

    expect($result->language)->toBe('en')
        ->and($result->driver)->toBe('whisper_cpp')
        ->and($result->empty)->toBeFalse()
        ->and($result->text)->toContain('Best tacos in Lisbon')
        ->and($result->segments)->toHaveCount(2)
        ->and($result->segments[0])->toBe(['start_ms' => 0, 'end_ms' => 2400, 'text' => 'Best tacos in Lisbon,']);

    // The driver parses the JSON file it wrote, then cleans it up.
    expect(file_exists($wav.'.json'))->toBeFalse();
    @unlink($wav);
});

it('raises TranscriptionFailed when whisper.cpp exits non-zero', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'model load failed', exitCode: 1)]);

    (new WhisperCppTranscriber)->transcribe(tempWav());
})->throws(TranscriptionFailed::class);

it('transcribes via the hosted OpenAI-compatible endpoint (verbose_json → ms segments)', function () {
    config()->set('transcription.hosted.enabled', true);
    config()->set('transcription.hosted.base_url', 'https://api.test/v1');
    config()->set('transcription.hosted.api_key', 'sk-test');
    Http::fake(['*/audio/transcriptions' => Http::response(json_decode(transcriptionFixture('hosted_verbose.json'), true))]);

    $result = (new HostedTranscriber)->transcribe(tempWav());

    expect($result->language)->toBe('portuguese')
        ->and($result->driver)->toBe('hosted')
        ->and($result->text)->toBe('Melhor restaurante de Lisboa.')
        ->and($result->segments)->toHaveCount(2)
        ->and($result->segments[1])->toBe(['start_ms' => 2500, 'end_ms' => 5000, 'text' => 'de Lisboa.']);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/audio/transcriptions')
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && collect($request->data())->contains(fn ($p) => $p['name'] === 'response_format' && $p['contents'] === 'verbose_json');
    });
});

it('reports hosted availability from enabled flag + api key', function () {
    config()->set('transcription.hosted.enabled', false);
    expect((new HostedTranscriber)->isAvailable())->toBeFalse();

    config()->set('transcription.hosted.enabled', true);
    config()->set('transcription.hosted.api_key', '');
    expect((new HostedTranscriber)->isAvailable())->toBeFalse();

    config()->set('transcription.hosted.api_key', 'sk');
    expect((new HostedTranscriber)->isAvailable())->toBeTrue();
});

it('falls back to hosted when the primary driver is unavailable', function () {
    config()->set('transcription.hosted.enabled', true);
    config()->set('transcription.hosted.base_url', 'https://api.test/v1');
    config()->set('transcription.hosted.api_key', 'sk-test');
    Http::fake(['*/audio/transcriptions' => Http::response(json_decode(transcriptionFixture('hosted_verbose.json'), true))]);

    $manager = new TranscriptionManager(new FakeTranscriber(available: false), new HostedTranscriber);
    $result = $manager->transcribe(tempWav(), 1);

    expect($result->driver)->toBe('hosted');
});

it('falls back to hosted when the primary driver throws', function () {
    config()->set('transcription.hosted.enabled', true);
    config()->set('transcription.hosted.base_url', 'https://api.test/v1');
    config()->set('transcription.hosted.api_key', 'sk-test');
    Http::fake(['*/audio/transcriptions' => Http::response(json_decode(transcriptionFixture('hosted_verbose.json'), true))]);

    $manager = new TranscriptionManager(new FakeTranscriber(available: true, throws: true), new HostedTranscriber);

    expect($manager->transcribe(tempWav(), 1)->driver)->toBe('hosted');
});

it('throws TranscriptionFailed when every driver is unavailable', function () {
    config()->set('transcription.hosted.enabled', false);

    $manager = new TranscriptionManager(new FakeTranscriber(available: false), new HostedTranscriber);

    $manager->transcribe(tempWav(), 1);
})->throws(TranscriptionFailed::class);

it('prefers the primary driver when it is available', function () {
    $primary = new FakeTranscriber(available: true, result: new TranscriptionResult('en', 'local wins', [], 'fake'));
    $manager = new TranscriptionManager($primary, new HostedTranscriber);

    expect($manager->transcribe(tempWav(), 1)->text)->toBe('local wins')
        ->and($primary->transcribed)->toBeTrue();
});
