<?php

namespace App\Services\Places\Enrichment\Sources;

use App\Models\Place;
use App\Services\Http\PublicUrlGuard;
use App\Services\Places\Enrichment\BusinessEnrichmentSource;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enriches a place from the business's own website / menu (T-084): fetches the
 * site's homepage (the `website` field) and reads its schema.org JSON-LD to fill
 * address, phone, cuisine, opening hours, and a business image — the fields the
 * Google mask doesn't carry.
 *
 * ToS/SSRF: the URL is vetted public via {@see PublicUrlGuard} (no private/loopback
 * IPs) and redirects are disabled, so a 30x can't bounce to an internal address.
 * The body is capped and the whole scrape is cached per website URL. Config-gated
 * by `places.enrich.website.enabled`.
 */
class WebsiteBusinessSource implements BusinessEnrichmentSource
{
    /** schema.org @type values we treat as a business node (case-insensitive). */
    private const BUSINESS_TYPE_HINTS = [
        'restaurant', 'foodestablishment', 'localbusiness', 'cafeorcoffeeshop',
        'barorpub', 'bakery', 'store', 'winery', 'brewery', 'nightclub', 'hotel',
    ];

    public function __construct(private readonly PublicUrlGuard $guard) {}

    public function id(): string
    {
        return 'website';
    }

    /**
     * @return array<string, mixed>
     */
    public function enrich(Place $place): array
    {
        if (! (bool) config('places.enrich.website.enabled', true)) {
            return [];
        }

        $website = trim((string) $place->website);
        if ($website === '') {
            return [];
        }

        $cacheKey = 'places:enrich:website:'.sha1($website);
        $days = max(1, (int) config('places.enrich.website.cache_days', 7));

        // A successful scrape (even an empty patch) is cached; a fetch/SSRF error
        // throws out of the closure so it is NOT cached and the enricher marks the
        // source failed — the next run retries.
        return Cache::remember($cacheKey, now()->addDays($days), fn (): array => $this->scrape($website));
    }

    /**
     * @return array<string, mixed>
     */
    private function scrape(string $website): array
    {
        $this->guard->assertPublic(
            $website,
            allowedSchemes: ['https', 'http'],
            verifyHost: (bool) config('places.enrich.website.verify_host', true),
        );

        // Stream with a hard byte cap so a hostile/huge body can't exhaust memory
        // (the cap must bound the READ, not trim an already-buffered body). No
        // redirects: the SSRF guard only vetted THIS host.
        $response = Http::timeout((int) config('places.enrich.website.timeout_seconds', 8))
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get($website);

        // A non-2xx must NOT be cached as "no data" — throw so the enricher marks
        // the source failed and the next run retries (see this method's contract).
        if (! $response->successful()) {
            throw new RuntimeException('website fetch returned HTTP '.$response->status());
        }

        $html = $this->readCapped($response, (int) config('places.enrich.website.max_bytes', 512 * 1024));
        $node = $this->firstBusinessNode($html);
        if ($node === null) {
            return [];
        }

        return array_filter([
            'phone' => $this->str($node['telephone'] ?? null, 32),
            'cuisine_primary' => $this->str($this->first($node['servesCuisine'] ?? null), 120),
            'image_url' => $this->httpUrl($this->first($node['image'] ?? null)),
            'opening_hours_json' => $this->openingHours($node),
            'address_line1' => $this->str($this->addressPart($node, 'streetAddress'), 255),
            'city' => $this->str($this->addressPart($node, 'addressLocality'), 255),
            'region' => $this->str($this->addressPart($node, 'addressRegion'), 255),
            'postal_code' => $this->str($this->addressPart($node, 'postalCode'), 32),
            'country_code' => $this->countryCode($this->addressPart($node, 'addressCountry')),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Read at most $maxBytes from a streamed response body, chunk by chunk, so a
     * multi-gigabyte body is never fully resident in memory.
     */
    private function readCapped(Response $response, int $maxBytes): string
    {
        $body = $response->toPsrResponse()->getBody();
        $html = '';
        while (! $body->eof() && strlen($html) < $maxBytes) {
            $html .= $body->read(8192);
        }

        return substr($html, 0, $maxBytes);
    }

    /**
     * The first schema.org business object across every JSON-LD block on the page
     * (each block may be an object, an array, or a `@graph` wrapper).
     *
     * @return array<string, mixed>|null
     */
    private function firstBusinessNode(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $block) {
            $decoded = json_decode(trim((string) $block), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->flatten($decoded) as $node) {
                if (is_array($node) && $this->isBusiness($node['@type'] ?? null)) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * Flatten a decoded JSON-LD value to a list of candidate objects — a bare
     * object, a list of objects, or the members of a `@graph`.
     *
     * @param  array<int|string, mixed>  $decoded
     * @return list<mixed>
     */
    private function flatten(array $decoded): array
    {
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            return array_values($decoded['@graph']);
        }

        // A list (numeric keys) is already a set of nodes; a single object wraps to one.
        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    /** Whether an @type (string or list) names a business we understand. */
    private function isBusiness(mixed $type): bool
    {
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $t) {
            if (is_string($t) && in_array(strtolower($t), self::BUSINESS_TYPE_HINTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opening hours as a list of human-readable lines, from either the flat
     * `openingHours` string list or `openingHoursSpecification` objects.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function openingHours(array $node): array
    {
        $flat = $node['openingHours'] ?? null;
        if (is_string($flat) && trim($flat) !== '') {
            return [trim($flat)];
        }
        if (is_array($flat)) {
            $lines = array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? trim($v) : null,
                $flat,
            )));
            if ($lines !== []) {
                return $lines;
            }
        }

        $spec = $node['openingHoursSpecification'] ?? null;
        if (! is_array($spec)) {
            return [];
        }
        $specs = array_is_list($spec) ? $spec : [$spec];

        $lines = [];
        foreach ($specs as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $days = $entry['dayOfWeek'] ?? null;
            $dayLabels = array_map(
                fn ($d) => is_string($d) ? Str::afterLast(rtrim($d, '/'), '/') : '',
                is_array($days) ? $days : [$days],
            );
            $dayLabel = implode(', ', array_filter($dayLabels));
            $opens = is_string($entry['opens'] ?? null) ? $entry['opens'] : '';
            $closes = is_string($entry['closes'] ?? null) ? $entry['closes'] : '';
            // The trims collapse the empty cases, so a plain separator suffices.
            $hours = trim($opens.'–'.$closes, '–');
            $line = trim($dayLabel.' '.$hours);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * A single address component from the node's `address` (a PostalAddress
     * object, or a list of them — the first is used).
     *
     * @param  array<string, mixed>  $node
     */
    private function addressPart(array $node, string $key): mixed
    {
        $address = $node['address'] ?? null;
        if (is_array($address) && array_is_list($address)) {
            $address = $address[0] ?? null;
        }

        return is_array($address) ? ($address[$key] ?? null) : null;
    }

    /** First element of a value that may be a scalar, a list, or an object with url/name. */
    private function first(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return $value[0] ?? null;
            }

            return $value['url'] ?? $value['name'] ?? null;
        }

        return $value;
    }

    /** A trimmed, length-capped string, or null when empty/non-scalar. */
    private function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    /** An http(s) URL (length-capped), or null. */
    private function httpUrl(mixed $value): ?string
    {
        $url = $this->str($value, 2048);

        return $url !== null && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /** A 2-letter ISO country code (upper-cased), or null — never a full name. */
    private function countryCode(mixed $value): ?string
    {
        $code = $this->str($value, 8);

        return $code !== null && preg_match('/^[A-Za-z]{2}$/', $code) === 1 ? strtoupper($code) : null;
    }
}
