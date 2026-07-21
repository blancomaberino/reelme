<?php

use App\Enums\ShareStatus;
use App\Jobs\IngestShare;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * request_id correlation (T-092): AssignRequestId mints one id per request,
 * echoes it as X-Request-Id, the error envelope reuses it, and it rides Context
 * onto the async pipeline so a share's stage logs trace back to the request.
 */
it('echoes X-Request-Id and reuses it in the error envelope', function () {
    // A matched API route that errors (unauthenticated) — the middleware group
    // (incl. AssignRequestId) runs, then auth throws; the renderer must still
    // carry the id on both the header and the envelope.
    $res = $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/ABC123/'])
        ->assertStatus(401);

    $header = $res->headers->get('X-Request-Id');
    expect($header)->toStartWith('req_')
        ->and($res->json('error.request_id'))->toBe($header);
});

it('echoes X-Request-Id on a successful response too', function () {
    Sanctum::actingAs(User::factory()->create());

    $res = $this->getJson('/api/v1/me')->assertOk();

    expect($res->headers->get('X-Request-Id'))->toStartWith('req_');
});

it('gives each request a distinct id', function () {
    $a = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
    $b = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');

    expect($a)->not->toBe($b);
});

it('propagates the request id from Context into a dispatched pipeline job log', function () {
    Bus::fake();   // stop the chain from cascading into the real pipeline
    Log::spy();

    // The middleware would have set this on the originating request; Laravel
    // serializes Context onto the queued job, so the worker sees the same id.
    Context::add('request_id', 'req_JOB_CORRELATION');

    $share = Share::factory()->create(['status' => ShareStatus::Pending]);
    (new IngestShare($share->id))->handle();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $ctx): bool => $message === 'pipeline.ingest.start'
            && ($ctx['share_id'] ?? null) === $share->id
            && ($ctx['request_id'] ?? null) === 'req_JOB_CORRELATION')
        ->once();
});
