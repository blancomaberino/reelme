<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Keeps the viewer's coordinates — and, since they travel in the same query
 * strings, this app's outbound API keys — out of Sentry (T-156).
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
     * Query parameters redacted by NAME, whatever their value.
     *
     * `near` is the map's and the listing's; add here rather than at a call
     * site, so a new endpoint that adopts the same spelling is covered by
     * construction rather than by someone remembering.
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
    private const COORD_PAIR = '/-?\d{1,3}\.\d{3,}(?:\s*,\s*|%2C)-?\d{1,3}\.\d{3,}/i';

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

        // SPANS are the fourth carrier, and the one that makes the transaction
        // hook self-defeating without this. `HttpClientIntegration` puts the
        // SAME unmodified query string on an `http.client` span as it puts on
        // the breadcrumb — so `before_send_transaction`, which only ever fires
        // when tracing is on and therefore only ever sees events that HAVE
        // spans, was walking straight past the carrier it was added for.
        // SPANS are a carrier too, and the one that made the transaction hook
        // self-defeating without this: `HttpClientIntegration` puts the SAME
        // query string on an `http.client` span as on the breadcrumb, and
        // `before_send_transaction` only ever fires when tracing is on, which is
        // the only time an event HAS spans.
        foreach ($event->getSpans() as $span) {
            $span->setData(self::scrubBag($span->getData()));

            if (($description = $span->getDescription()) !== null) {
                $span->setDescription(self::scrubValue('description', $description));
            }
        }

        // And the app's OWN doors. `contexts` and `extra` take whatever a caller
        // hands them — `SentryErrorReporter` passes a context array through
        // untouched — so they are one `'near' => $near` away from being the next
        // carrier. Walked by SHAPE rather than by name, which is the difference
        // between this converging and it needing another round per field.
        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::scrubBag($context));
        }

        $event->setExtra(self::scrubBag($event->getExtra()));

        return $event;
    }

    /**
     * Scrub the string leaves of a key/value bag, one level of nesting deep.
     *
     * Not a recursive walk of arbitrary depth: the carriers this exists for are
     * flat, and an unbounded rewrite of anything anyone ever attaches is a
     * bigger promise than this can keep. Two levels covers `contexts`, whose
     * shape is `['name' => ['k' => 'v']]`.
     *
     * @param  array<array-key, mixed>  $bag
     * @return array<array-key, mixed>
     */
    private static function scrubBag(array $bag): array
    {
        foreach ($bag as $key => $value) {
            if (is_string($value)) {
                $bag[$key] = self::scrubValue((string) $key, $value);
            } elseif (is_array($value)) {
                foreach ($value as $innerKey => $inner) {
                    if (is_string($inner)) {
                        $value[$innerKey] = self::scrubValue((string) $innerKey, $inner);
                    }
                }
                $bag[$key] = $value;
            }
        }

        return $bag;
    }

    /**
     * One string, scrubbed according to what its key says it is.
     *
     * The key names are an optimisation. `scrubUnknownMetadata` is the actual
     * defence, because it matches on SHAPE — which is what closes an SDK whose
     * field names cannot be enumerated ahead of time, and the reason a fifth
     * carrier does not need a fifth round.
     */
    private static function scrubValue(string $key, string $value): string
    {
        return match ($key) {
            'url' => self::scrubUrl($value),
            'http.query', 'query_string' => self::scrubQueryString($value),
            default => self::scrubUnknownMetadata($value),
        };
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
            if (is_string($value)) {
                $crumb = $crumb->withMetadata((string) $key, self::scrubValue((string) $key, $value));
            }
        }

        return $crumb;
    }

    /**
     * Metadata under a key this class does not know.
     *
     * The name table above is an optimisation, not the defence, and it must not
     * be the only thing standing between an API key and an error report. The
     * asymmetry is why: if a future SDK version renames `http.query`, a
     * coordinate still degrades safely — `scrubText` decodes and the pair regex
     * catches it — but a credential has no value shape to match, so it would
     * ship silently. So anything that PARSES as a query string gets the by-name
     * pass too, whatever it is called.
     *
     * Gated on looking like one: at least one `k=v`, no whitespace. Running
     * `scrubQueryString` over ordinary prose would collapse it on `&`.
     */
    private static function scrubUnknownMetadata(string $value): string
    {
        // A `?` means a URL, and the query has to be split off before the
        // by-name pass — otherwise the first pair's KEY is the whole
        // `https://…/json?key`, which matches nothing in the table, and the
        // credential ships verbatim.
        //
        // But the TAIL still has to look like a query string, and that gate is
        // load-bearing rather than tidy. Without it, prose containing a `?` took
        // this path: ``Client error: `GET …?location=…&key=AIza…` resulted in a
        // 403`` had everything after the credential DELETED, because `key`'s
        // value swallowed the rest of the sentence. And a span description
        // carrying PostGIS SQL lost a `&` from the `&&` bbox operator.
        if (str_contains($value, '?')) {
            [$path, $query] = array_pad(explode('?', $value, 2), 2, '');

            return self::looksLikeQueryString($query)
                ? $path.'?'.self::scrubQueryString($query)
                : self::scrubText($value);
        }

        return self::looksLikeQueryString($value)
            ? self::scrubQueryString($value)
            : self::scrubText($value);
    }

    /**
     * At least one `k=v`, and no whitespace anywhere.
     *
     * The whitespace rule is what keeps prose out. `scrubQueryString` splits on
     * `&` and `=` and re-joins, so anything handed to it that is not actually a
     * query string comes back rearranged — text after a redacted key vanishes,
     * and a doubled `&&` collapses.
     */
    private static function looksLikeQueryString(string $value): bool
    {
        return preg_match('/^[^\s=&]+=[^\s&]*(&[^\s=&]+=[^\s&]*)*$/', $value) === 1;
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
            // A bare flag stays a bare flag: `?near&sort=…` must not come back
            // as `near=[redacted]`, which would report a value the request did
            // not carry — the same "a scrubber must not invent fields" rule the
            // decode round-trip was removed for.
            if ($value === null) {
                $pairs[] = $key;

                continue;
            }

            $pairs[] = in_array(strtolower(rawurldecode($key)), self::REDACTED_PARAMS, true)
                ? $key.'='.self::REDACTED
                : $key.'='.self::scrubText($value);
        }

        return implode('&', $pairs);
    }

    /**
     * The separator is matched in BOTH spellings — a literal comma and `%2C` —
     * rather than by decoding first.
     *
     * Decoding was the previous attempt and it went wrong twice. Returning the
     * decoded form let a caller-controlled value forge structure
     * (`?q=x%26near%3D…` came back reading as two parameters); re-encoding to
     * fix that percent-encoded the ENTIRE message, so an exception value became
     * `cURL%20error%2028%3A%20…` and the redaction marker itself became
     * `%5Bredacted%5D`. Matching both spellings needs neither.
     */
    private static function scrubText(string $text): string
    {
        // `?? $text` and not a cast: a PCRE failure returns null, and casting
        // that to a string blanks the field instead of leaving it — erasing the
        // message an operator needed, quietly, in the failure case.
        return preg_replace(self::COORD_PAIR, self::REDACTED, $text) ?? $text;
    }
}
