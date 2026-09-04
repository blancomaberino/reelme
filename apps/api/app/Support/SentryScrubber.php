<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Keeps the viewer's coordinates out of Sentry (T-156).
 *
 * `send_default_pii => false` does not do this. Sentry's RequestIntegration
 * sets `request.url` and `request.query_string` BEFORE its PII branch, so the
 * flag governs cookies, headers and REMOTE_ADDR while the URL passes through
 * untouched. T-156 puts `?near=lat,lng` on the map endpoint — sent on every map
 * open and every pan, automatically, with no user action — so a single 500 on
 * that route would export a ~11 m fix on a real person to a processor that
 * `DELETE /me` cannot reach.
 *
 * A STATIC method rather than a closure because `config/sentry.php` is passed
 * through `artisan config:cache` on every deploy, and a closure in a cached
 * config file is a fatal serialization error. An array callable is a pair of
 * strings and caches fine.
 *
 * It redacts rather than drops: the stack trace is the reason the event exists,
 * and the coordinates are never the thing that makes it debuggable.
 */
class SentryScrubber
{
    /**
     * Query parameters whose VALUES are a position, wherever they appear.
     *
     * `near` is the map's and the listing's; add here rather than at a call
     * site, so a new endpoint that adopts the same spelling is covered by
     * construction rather than by someone remembering.
     */
    private const REDACTED_PARAMS = ['near', 'lat', 'lng', 'latitude', 'longitude'];

    private const REDACTED = '[redacted]';

    /**
     * A bare coordinate pair, for the one carrier a query-string scrub misses.
     *
     * A `QueryException` formats its BINDINGS into the message, and the
     * coordinates are bound into `ST_MakePoint(?, ?)` — so a database failure on
     * the map route puts them in the exception text, which no `sql_bindings`
     * setting governs. Deliberately narrow: two signed decimals with at least
     * three fractional digits each, separated by a comma or ", ". A bare
     * integer pair is not matched, so ordinary numbers in a message survive.
     */
    private const COORD_PAIR = '/-?\d{1,3}\.\d{3,}\s*,\s*-?\d{1,3}\.\d{3,}/';

    public static function scrub(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if (isset($request['query_string']) && is_string($request['query_string'])) {
            $request['query_string'] = self::scrubQueryString($request['query_string']);
        }

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = self::scrubUrl($request['url']);
        }

        if ($request !== []) {
            $event->setRequest($request);
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrubText($exception->getValue()));
        }

        $event->setBreadcrumb(array_map(self::scrubBreadcrumb(...), $event->getBreadcrumbs()));

        return $event;
    }

    /**
     * Breadcrumbs are the third carrier, and the one that survives a scrub of
     * the other two.
     *
     * `breadcrumbs.logs` is on by default, so Laravel's own log of a
     * `QueryException` becomes a breadcrumb — bindings already interpolated,
     * which is how `ST_MakePoint(-56.1645, -34.9011)` gets into a message that
     * no `sql_bindings` setting governs. A TRANSACTION then inherits the scope's
     * breadcrumbs while carrying no exceptions of its own, so scrubbing
     * `getExceptions()` walks straight past it: raise
     * `SENTRY_TRACES_SAMPLE_RATE` during an incident — the exact change the
     * `before_send_transaction` hook was added for — and the position ships
     * anyway, by a different door.
     */
    private static function scrubBreadcrumb(Breadcrumb $crumb): Breadcrumb
    {
        $message = $crumb->getMessage();

        if ($message !== null) {
            $crumb = $crumb->withMessage(self::scrubText($message));
        }

        // Metadata is where the log channel puts a message's `context` and the
        // HTTP integration puts a request URL. Strings only — a nested array or
        // an object is left alone rather than walked, because the carriers this
        // exists for are all flat text and a recursive rewrite of arbitrary
        // metadata is a bigger promise than this can keep.
        //
        // `(string) $key` is load-bearing: `Log::warning('x', ['a', 'b'])` is
        // legal Laravel and yields INTEGER metadata keys, which `withMetadata`
        // types as `string`. Under `declare(strict_types=1)` — one lint sweep
        // away — that TypeErrors, and the SDK does not wrap `before_send`, so
        // the throw propagates out of `captureException()`: telemetry taking
        // down the request it was watching.
        foreach ($crumb->getMetadata() as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            // A `url` carries its parameters, so it gets the query-string pass
            // as well — `?lat=…&lng=…` is redacted by NAME there, which the
            // coordinate-pair regex would miss entirely.
            $crumb = $crumb->withMetadata(
                (string) $key,
                $key === 'url' ? self::scrubUrl($value) : self::scrubText($value),
            );
        }

        return $crumb;
    }

    private static function scrubUrl(string $url): string
    {
        $parts = explode('?', $url, 2);

        return count($parts) === 2
            ? $parts[0].'?'.self::scrubQueryString($parts[1])
            : self::scrubText($parts[0]);
    }

    /**
     * Rewrites the query string field by field rather than by regex over the
     * whole thing, so a value that merely CONTAINS a comma-separated pair (a
     * search term, a slug) keeps its meaning while `near` loses its.
     */
    private static function scrubQueryString(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $pairs[] = in_array(strtolower(rawurldecode($key)), self::REDACTED_PARAMS, true)
                ? $key.'='.self::REDACTED
                : ($value === null ? $key : $key.'='.self::scrubText($value));
        }

        return implode('&', $pairs);
    }

    private static function scrubText(string $text): string
    {
        return (string) preg_replace(self::COORD_PAIR, self::REDACTED, $text);
    }
}
