<?php

use App\Support\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;

/**
 * T-156. The viewer's position now travels in a query string on the app's
 * busiest endpoint, and `send_default_pii => false` does NOT keep it out of
 * Sentry — that flag gates cookies, headers and the IP, while the URL is
 * attached before it is consulted. These pin the two carriers.
 */
function scrubbed(array $request = [], ?string $exceptionMessage = null): Event
{
    $event = Event::createEvent();
    if ($request !== []) {
        $event->setRequest($request);
    }
    if ($exceptionMessage !== null) {
        $event->setExceptions([new ExceptionDataBag(new RuntimeException($exceptionMessage))]);
    }

    return SentryScrubber::scrub($event);
}

it('redacts the viewer position from the query string, keeping every other parameter', function () {
    $event = scrubbed(['query_string' => 'bbox=-56.3,-35.0,-56.0,-34.8&zoom=15&near=-34.9011,-56.1645&dish=pasta']);

    $query = $event->getRequest()['query_string'];

    // The position is gone...
    expect($query)->not->toContain('-34.9011')
        ->and($query)->not->toContain('-56.1645')
        // ...and everything a triager actually needs survives. A scrub that
        // takes the bbox with it would make every map report undebuggable,
        // which is how a redaction gets switched off.
        ->and($query)->toContain('bbox=-56.3,-35.0,-56.0,-34.8')
        ->and($query)->toContain('zoom=15')
        ->and($query)->toContain('dish=pasta')
        ->and($query)->toContain('near=[redacted]');
});

it('redacts the position from the full URL, not only the query_string field', function () {
    // RequestIntegration sets BOTH, and they are independent strings. Scrubbing
    // one and not the other exports the coordinate anyway — which is the whole
    // failure this guards, arriving through the second door.
    $event = scrubbed(['url' => 'https://api.reelmap.app/api/v1/map/places?zoom=15&near=-34.9011,-56.1645']);

    expect($event->getRequest()['url'])
        ->toBe('https://api.reelmap.app/api/v1/map/places?zoom=15&near=[redacted]');
});

it('redacts a coordinate pair out of an exception MESSAGE', function () {
    // The carrier a query-string scrub cannot reach: a QueryException formats
    // its bindings into the message, and the coordinates are bound into
    // ST_MakePoint(?, ?). No `sql_bindings` setting governs the message text.
    $event = scrubbed(exceptionMessage: 'SQLSTATE[XX000]: ST_Distance(location, ST_MakePoint(-56.1645, -34.9011)::geography)');

    $value = $event->getExceptions()[0]->getValue();

    expect($value)->not->toContain('-34.9011')
        ->and($value)->toContain('[redacted]')
        // The SQL around it still reads, or the report is worthless.
        ->and($value)->toContain('ST_Distance');
});

it('leaves an ordinary message alone — a redaction that eats everything gets turned off', function () {
    // The positive control for the regex's narrowness. Integers and single
    // decimals are not coordinates; a pattern that ate them would redact ids,
    // counts and version numbers out of every report in the project.
    $event = scrubbed(exceptionMessage: 'Job 12, 34 failed after 1.5 seconds (attempt 2 of 3)');

    expect($event->getExceptions()[0]->getValue())->toBe('Job 12, 34 failed after 1.5 seconds (attempt 2 of 3)');
});

it('survives an event with no request and no exceptions', function () {
    // Most events are not HTTP. A scrubber that throws on one drops the report
    // entirely, which is strictly worse than the leak it was written to stop.
    expect(fn () => SentryScrubber::scrub(Event::createEvent()))->not->toThrow(Throwable::class);
});

it('redacts the position under any of its spellings, and case-insensitively', function () {
    // `near` is this route's; the others are the shapes a future endpoint most
    // plausibly reaches for. Covered here so adopting one is safe by
    // construction rather than by someone remembering this file exists.
    $event = scrubbed(['query_string' => 'Near=1.5,2.5&lat=-34.90111&LNG=-56.16455&latitude=1.1&longitude=2.2']);

    $query = $event->getRequest()['query_string'];

    expect($query)->toBe('Near=[redacted]&lat=[redacted]&LNG=[redacted]&latitude=[redacted]&longitude=[redacted]');
});

it('is registered for TRANSACTIONS as well as errors — the type gate is in the SDK, not in us', function () {
    // `Client::prepareEvent()` dispatches by event type: `before_send` fires for
    // errors, `before_send_transaction` for transactions. `RequestIntegration`
    // attaches `request.url`/`request.query_string` through a global processor
    // with NO type gate, so a transaction carries the same query string. With
    // only the error hook registered, turning `SENTRY_TRACES_SAMPLE_RATE` above
    // 0 during an incident silently began exporting the coordinates.
    //
    // Asserted at the CONFIG, because the defect was never in the method — it
    // was in which of the SDK's hooks the method was wired to, and no test of
    // `scrub()` itself could see that.
    // Read from the file rather than the container: this is a Unit test with no
    // application booted, and the file is the artifact that ships.
    $config = require dirname(__DIR__, 2).'/config/sentry.php';

    expect($config['before_send'])->toBe([SentryScrubber::class, 'scrub'])
        ->and($config['before_send_transaction'])->toBe([SentryScrubber::class, 'scrub']);
});

it('scrubs a transaction event, not just an error one', function () {
    $event = Event::createTransaction();
    $event->setRequest(['query_string' => 'zoom=15&near=-34.9011,-56.1645']);

    $scrubbed = SentryScrubber::scrub($event);

    expect($scrubbed->getRequest()['query_string'])->toBe('zoom=15&near=[redacted]');
});

it('redacts a coordinate pair out of a BREADCRUMB, the carrier a transaction inherits', function () {
    // `breadcrumbs.logs` is on by default, so Laravel's own log of a
    // QueryException becomes a breadcrumb with the bindings already interpolated.
    // A transaction inherits the scope's breadcrumbs and carries no exceptions of
    // its own, so scrubbing `getExceptions()` walks straight past it: raising
    // SENTRY_TRACES_SAMPLE_RATE during an incident — the change the
    // `before_send_transaction` hook was added for — shipped the position anyway.
    $event = Event::createTransaction();
    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_ERROR,
            Breadcrumb::TYPE_DEFAULT,
            'log',
            'SQLSTATE[XX000]: ST_MakePoint(-56.1645, -34.9011)::geography',
            ['sql' => 'ST_DWithin(location, ST_MakePoint(-56.1645, -34.9011)::geography, 2000)'],
        ),
    ]);

    $crumb = SentryScrubber::scrub($event)->getBreadcrumbs()[0];

    expect($crumb->getMessage())->toBe('SQLSTATE[XX000]: ST_MakePoint([redacted])::geography')
        ->and($crumb->getMetadata()['sql'])
        ->toBe('ST_DWithin(location, ST_MakePoint([redacted])::geography, 2000)');
});

it('leaves an ordinary breadcrumb alone', function () {
    // The control. A redaction that eats every breadcrumb is one somebody turns
    // off, and then none of the above protects anything.
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Job 12, 34 finished in 1.5s'),
    ]);

    expect(SentryScrubber::scrub($event)->getBreadcrumbs()[0]->getMessage())
        ->toBe('Job 12, 34 finished in 1.5s');
});

it('redacts by NAME in a breadcrumb url, where the coordinate-pair regex would not', function () {
    // An HTTP breadcrumb carries the request URL in its metadata. `?lat=…&lng=…`
    // as separate parameters is not a coordinate PAIR, so `scrubText` alone
    // walks past it — the query-string pass is what catches it.
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            ['url' => 'https://api.reelmap.app/api/v1/places?lat=-34.9011&lng=-56.1645&sort=distance'],
        ),
    ]);

    expect(SentryScrubber::scrub($event)->getBreadcrumbs()[0]->getMetadata()['url'])
        ->toBe('https://api.reelmap.app/api/v1/places?lat=[redacted]&lng=[redacted]&sort=distance');
});

it('survives a breadcrumb whose metadata keys are integers', function () {
    // `Log::warning('x', ['a', 'b'])` is legal and yields a LIST as context, so
    // the keys are ints while `withMetadata()` types its name as string. The SDK
    // does not wrap `before_send`, so a TypeError here would propagate out of
    // `captureException()` — telemetry taking down the request it was watching.
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_WARNING, Breadcrumb::TYPE_DEFAULT, 'log', 'x', ['a', 'b']),
    ]);

    expect(fn () => SentryScrubber::scrub($event))->not->toThrow(Throwable::class);
});
