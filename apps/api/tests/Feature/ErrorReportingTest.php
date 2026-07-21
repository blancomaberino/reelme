<?php

use App\Models\Share;
use App\Support\Observability\ErrorReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/**
 * Error tracking (T-091): unhandled HTTP exceptions and failed queue jobs are
 * captured through the ErrorReporter choke point with share_id + request_id.
 * A collecting fake stands in for the tracker transport.
 */
class CollectingErrorReporter implements ErrorReporter
{
    /** @var list<array{exception: Throwable, context: array<string, mixed>}> */
    public array $captures = [];

    public function capture(Throwable $e, array $context = []): void
    {
        $this->captures[] = ['exception' => $e, 'context' => $context];
    }
}

/** A pipeline-shaped job that always fails — carries a shareId like the real jobs. */
class FailingShareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $shareId) {}

    public function handle(): void
    {
        throw new RuntimeException('job boom');
    }
}

function fakeReporter(): CollectingErrorReporter
{
    $reporter = new CollectingErrorReporter;
    app()->instance(ErrorReporter::class, $reporter);

    return $reporter;
}

it('captures a server error from the HTTP handler with the request id', function () {
    $reporter = fakeReporter();
    Context::add('request_id', 'req_HTTP_CAPTURE');
    Route::get('/api/v1/__boom', fn () => throw new RuntimeException('kaboom'));

    $this->getJson('/api/v1/__boom')->assertStatus(500);

    expect($reporter->captures)->toHaveCount(1)
        ->and($reporter->captures[0]['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and($reporter->captures[0]['context']['request_id'])->toBe('req_HTTP_CAPTURE');
});

it('does NOT capture expected client errors (a 404 is normal flow)', function () {
    $reporter = fakeReporter();
    Route::get('/api/v1/__missing', fn () => throw new NotFoundHttpException);

    $this->getJson('/api/v1/__missing')->assertStatus(404);

    expect($reporter->captures)->toBe([]);
});

it('captures a failed queue job with its share_id and request id', function () {
    $reporter = fakeReporter();
    Context::add('request_id', 'req_JOB_CAPTURE');
    $share = Share::factory()->create();

    // Sync connection (tests): dispatch fires the real JobFailed path, then
    // re-throws to the caller — the failed-job hook captures before that.
    try {
        FailingShareJob::dispatch($share->id);
    } catch (Throwable) {
        // expected — the job throws
    }

    expect($reporter->captures)->toHaveCount(1)
        ->and($reporter->captures[0]['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and($reporter->captures[0]['context']['share_id'] ?? null)->toBe($share->id)
        ->and($reporter->captures[0]['context']['request_id'])->toBe('req_JOB_CAPTURE');
});
