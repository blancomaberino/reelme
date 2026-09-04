<?php

declare(strict_types=1);

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
    /**
     * Query parameters redacted by NAME, whatever their value.
     *
     * `location` and `lon` are here because the OUTBOUND calls this app makes
     * spell a coordinate that way — Google's timezone and places endpoints take
     * `?location=lat,lng` — and an HTTP breadcrumb carries their query string
     * verbatim. The credential names are here for the same reason: those same
     * URLs carry `?key=`, so a failed geocode put an API key in an error report.
     * A name list is the right instrument for a breadcrumb, where the value
     * cannot be pattern-matched reliably.
     */
    private const REDACTED_PARAMS = [
        'near', 'lat', 'lng', 'lon', 'latitude', 'longitude', 'location',
        'key', 'api_key', 'apikey', 'token', 'access_token', 'signature',
    ];

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
        // types as `string`. Strict mode is decided by the CALLING file, which
        // is why this one declares it at the top — without that the cast is
        // silently redundant, the test covering it cannot fail, and the
        // TypeError arrives the day somebody adds the declaration. The SDK does
        // not wrap `before_send`, so that throw propagates out of
        // `captureException()`: telemetry taking down the request it watched.
        foreach ($crumb->getMetadata() as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            // `http.query` is the carrier, and it took a reviewer reading the
            // SDK to find that out: an earlier version of this special-cased
            // `url`, which `HttpClientIntegration::getPartialUri()` rebuilds
            // from scheme/host/port/PATH — the query is stripped out of it and
            // put in a SIBLING key. So the branch matched no producer at all,
            // and the test covering it invented a shape nothing emits.
            //
            // Both are handled now: `url` in case a future producer keeps its
            // query, and `http.query`, which is where the parameters actually
            // are. By NAME, because that is what catches `?location=…&key=…` on
            // an outbound Google call — percent-encoded, so the coordinate-pair
            // regex never matched it either.
            $crumb = $crumb->withMetadata((string) $key, match ((string) $key) {
                'url' => self::scrubUrl($value),
                'http.query' => self::scrubQueryString($value),
                default => self::scrubText($value),
            });
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

    /**
     * Decoded before matching: a query string percent-encodes the separator, so
     * `-34.9011%2C-56.1645` never matched a pattern written around a comma. The
     * decode is for MATCHING only — the returned string is the original with
     * matches replaced, so a value that was encoded stays encoded.
     */
    private static function scrubText(string $text): string
    {
        $decoded = rawurldecode($text);

        // Matched on the decoded form, replaced on whichever form matched. A
        // value that arrived encoded is returned decoded only when it actually
        // carried a coordinate — which is the case where losing the encoding is
        // the lesser problem, since the text is already being rewritten.
        if ($decoded !== $text && preg_match(self::COORD_PAIR, $decoded) === 1) {
            return (string) preg_replace(self::COORD_PAIR, self::REDACTED, $decoded);
        }

        return (string) preg_replace(self::COORD_PAIR, self::REDACTED, $text);
    }
}
