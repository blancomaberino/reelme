<?php

use App\Support\Observability\ErrorReporter;
use App\Support\Observability\LogErrorReporter;
use App\Support\Observability\NullErrorReporter;
use App\Support\Observability\SentryErrorReporter;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;

/**
 * Error reporting to Sentry (T-052).
 *
 * The thing worth testing here is not "does it call the SDK" — it is that a
 * captured failure arrives CORRELATABLE. An event you cannot find is an event
 * you do not have, and the whole reason this app tags `share_id` is that a
 * pipeline failure is meaningless without knowing which share it was.
 */

/**
 * Point the SDK at an in-memory hub and return the events it would have sent.
 *
 * `before_send` returning null drops the event, so nothing leaves the process —
 * this test never needs a DSN, a network, or a real project.
 *
 * @param  array<string, mixed>  $options
 * @return Closure(): list<Event>
 */
function captureSentryEvents(array $options = []): Closure
{
    $events = [];

    $client = ClientBuilder::create([
        'dsn' => 'https://public@sentry.example.com/1',
        'before_send' => function (Event $event) use (&$events): ?Event {
            $events[] = $event;

            return null;
        },
        ...$options,
    ])->getClient();

    SentrySdk::setCurrentHub(new Hub($client));

    // `use (&$events)`, NOT an arrow fn: `fn () => $events` captures by VALUE at
    // creation time, so it would return the empty array forever while
    // `before_send` filled a different one — and every assertion below would
    // read as "the SDK sent nothing".
    return function () use (&$events): array {
        return $events;
    };
}

it('tags the event with the share and request it belongs to', function () {
    $events = captureSentryEvents();

    (new SentryErrorReporter)->capture(
        new RuntimeException('transcription blew up'),
        ['share_id' => 8412, 'request_id' => 'req_abc', 'job' => 'App\Jobs\TranscribeAudio', 'queue' => 'media'],
    );

    $tags = $events()[0]->getTags();

    // Tags, not `extra`: these are what you FILTER by. As extra they are only
    // visible once you already have the right event open, which is backwards —
    // the correlation exists to help you find it.
    expect($tags)->toMatchArray([
        // Stringified deliberately: a tag value that is not a string is dropped
        // silently, so an int share_id would simply never appear.
        'share_id' => '8412',
        'request_id' => 'req_abc',
        'job' => 'App\Jobs\TranscribeAudio',
        'queue' => 'media',
    ]);
});

it('keeps the full context on the event even for keys it does not tag', function () {
    $events = captureSentryEvents();

    (new SentryErrorReporter)->capture(
        new RuntimeException('boom'),
        ['share_id' => 1, 'engine' => 'openrouter', 'model' => 'gemini-2.0-flash'],
    );

    $event = $events()[0];

    // Not every key deserves to be an indexed tag — high-cardinality values
    // would blow up the tag index — but nothing should be THROWN AWAY either.
    expect($event->getContexts()['reelmap'])->toMatchArray(['engine' => 'openrouter', 'model' => 'gemini-2.0-flash'])
        ->and($event->getTags())->not->toHaveKey('engine');
});

it('does not invent a tag for a context key that is null', function () {
    $events = captureSentryEvents();

    // `request_id` is null for anything that did not start as an HTTP request —
    // the scheduler, a console command. A literal "" tag would make those look
    // like they had a request whose id was empty, and would match a filter.
    (new SentryErrorReporter)->capture(new RuntimeException('boom'), ['share_id' => 5, 'request_id' => null]);

    expect($events()[0]->getTags())->not->toHaveKey('request_id');
});

it('swallows a broken tracker rather than taking down the caller', function () {
    // Telemetry that can kill the thing it is watching is worse than none. This
    // reporter runs inside the queue's `failing` hook and the HTTP exception
    // handler — the two places least able to survive a second exception.
    SentrySdk::setCurrentHub(new class extends Hub
    {
        public function withScope(callable $callback): mixed
        {
            throw new RuntimeException('sentry is down');
        }
    });

    expect(fn () => (new SentryErrorReporter)->capture(new RuntimeException('boom')))->not->toThrow(Throwable::class);
});

describe('driver resolution', function () {
    it('sends nothing at all by default', function () {
        config(['observability.error_reporter' => 'null']);

        // CI, tests, and any un-provisioned environment. Silence is the default
        // because the alternative is a test suite that pages somebody.
        expect(app(ErrorReporter::class))->toBeInstanceOf(NullErrorReporter::class);
    });

    it('uses Sentry once a DSN is configured', function () {
        config(['observability.error_reporter' => 'sentry', 'observability.sentry_dsn' => 'https://public@example.com/1']);

        expect(app(ErrorReporter::class))->toBeInstanceOf(SentryErrorReporter::class);
    });

    it('falls back to structured logs when the driver is sentry but the DSN is missing', function () {
        config(['observability.error_reporter' => 'sentry', 'observability.sentry_dsn' => null]);

        // NOT NullErrorReporter. A missing DSN in production is a typo, not a
        // decision, and the failure mode of getting it wrong is "we stopped
        // hearing about errors and nobody noticed" — which is indistinguishable
        // from everything working.
        expect(app(ErrorReporter::class))->toBeInstanceOf(LogErrorReporter::class);
    });
});

it('never ships PII to a third party', function () {
    // T-050 built erasure guarantees over exactly this data, and a copy inside
    // a tracker is outside every one of them — `DELETE /me` cannot reach it.
    // Both of these are hard-coded rather than env-backed, so this test is what
    // stops a `vendor:publish --force` from quietly re-opening them.
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse();
});

it('tags every event with the release that produced it', function () {
    // Without a release, every regression reads as "always been broken" and
    // "did the deploy cause this" — the first question anyone asks — has no
    // answer. The deploy script sets SENTRY_RELEASE; CI/Forge SHAs are the
    // fallback so the tag is never simply absent.
    $definition = file_get_contents(config_path('sentry.php'));

    expect($definition)->toContain("env('SENTRY_RELEASE'")
        ->toContain('FORGE_DEPLOY_COMMIT')
        ->toContain('GITHUB_SHA');
});
