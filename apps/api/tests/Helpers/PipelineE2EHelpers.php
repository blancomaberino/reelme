<?php

/*
|--------------------------------------------------------------------------
| T-028 end-to-end pipeline helpers
|--------------------------------------------------------------------------
| Shared setup for tests/Feature/Pipeline/* — the M1 exit-criteria suite that
| drives a share pending → published entirely on fakes. Loaded from Pest.php so
| the helpers exist in every parallel worker. Names are pipeline-prefixed to
| avoid colliding with the per-file helpers in the Jobs/ suites (extraction(),
| ollamaChat(), tempVideo(), bindManager(), …), all of which are declared at
| collection time in the same process.
*/

use App\Adapters\AdapterRegistry;
use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\HostedTranscriber;
use App\Services\Transcription\TranscriptionManager;
use Tests\Support\FakePipelineVideoAdapter;
use Tests\Support\FakeTranscriber;

/**
 * The golden extraction payload (schema-valid against extraction.schema.json),
 * optionally overridden via dotted paths — e.g. `['confidence.overall' => 0.6]`.
 */
function pipelineExtractionJson(array $overrides = []): string
{
    $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/extraction/valid.json')), true);
    foreach ($overrides as $path => $value) {
        data_set($data, $path, $value);
    }

    return (string) json_encode($data);
}

/** An Ollama POST /api/chat response body carrying the given assistant content. */
function pipelineOllamaChat(string $content): array
{
    return ['message' => ['content' => $content], 'prompt_eval_count' => 10, 'eval_count' => 5];
}

/**
 * An OpenRouter POST /chat/completions response body. `usage.cost` drives the
 * run's cost_usd, so the default is a real billed amount the fallback test
 * asserts is > 0.
 */
function pipelineOpenRouterChat(string $content, array $overrides = []): array
{
    return array_replace([
        'model' => 'google/gemini-2.0-flash-001',
        'choices' => [['message' => ['content' => $content]]],
        'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 300, 'cost' => 0.0123],
    ], $overrides);
}

/**
 * Route the Instagram adapter chain to the media-capable fake so a shared reel
 * URL fetches metadata AND a real video (drives DownloadMedia/PrepareMedia).
 */
function useFakeVideoChain(string $fixture = 'sample.mp4'): void
{
    app()->bind(FakePipelineVideoAdapter::class, fn () => new FakePipelineVideoAdapter($fixture));
    config(['ingestion.chains.instagram' => [FakePipelineVideoAdapter::class]]);
    app()->forgetInstance(AdapterRegistry::class);
}

/**
 * Bind a TranscriptionManager whose only active driver is a FakeTranscriber — no
 * whisper binary, deterministic transcript. Pass `throws: true` to make that sole
 * driver fail, exercising the both-drivers-fail degrade path (the job stores an
 * empty transcript, never fails the share).
 *
 * The hosted fallback is disabled explicitly so `throws: true` is deterministic
 * regardless of the ambient env — otherwise the manager could fall through to the
 * real HostedTranscriber if a hosted key happened to be configured.
 */
function bindFakeTranscription(?TranscriptionResult $result = null, bool $throws = false): void
{
    config()->set('transcription.hosted.enabled', false);

    $primary = new FakeTranscriber(
        result: $result ?? new TranscriptionResult(
            language: 'en',
            text: 'they pull the noodles right in front of you',
            segments: [['start_ms' => 0, 'end_ms' => 2400, 'text' => 'they pull the noodles right in front of you']],
            driver: 'fake',
        ),
        throws: $throws,
    );

    app()->instance(TranscriptionManager::class, new TranscriptionManager($primary, app(HostedTranscriber::class)));
}
