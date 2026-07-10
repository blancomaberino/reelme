<?php

namespace App\Services\Ingestion;

use App\Adapters\AdapterRegistry;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Turns a raw shared URL into a CanonicalUrl: expands shortlinks (following a
 * bounded number of SSRF-checked redirects), strips tracking params, and
 * extracts the platform post id. Used by POST /shares (ShareController) to
 * resolve the source_post + duplicate guard.
 */
class UrlCanonicalizer
{
    private const SHORTLINK_HOSTS = ['vm.tiktok.com', 'vt.tiktok.com', 'youtu.be', 't.co'];

    private const TRACKING_PARAMS = ['igsh', 'si', 'feature', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function __construct(private readonly AdapterRegistry $registry) {}

    public function canonicalize(string $rawUrl): CanonicalUrl
    {
        $url = trim($rawUrl);

        if ($this->isShortlink($url)) {
            $url = $this->expand($url);
        }

        $url = $this->stripTracking($url);
        $platform = $this->registry->platformFor($url);
        $externalId = $platform !== null ? $this->externalId($platform, $url) : null;

        return new CanonicalUrl($url, $platform, $externalId);
    }

    private function isShortlink(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::SHORTLINK_HOSTS, true);
    }

    /**
     * Expand a shortlink by following redirects MANUALLY, validating every hop's
     * target against private/reserved IP ranges. Guzzle's auto-redirect would
     * only gate the first host (allowlisted); a t.co / attacker redirect could
     * otherwise reach 169.254.169.254, localhost, or internal services (SSRF).
     */
    private function expand(string $url): string
    {
        $current = $url; // starting host is already allowlisted by the caller

        for ($hop = 0; $hop < 3; $hop++) {
            try {
                $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($current);
            } catch (ConnectionException) {
                return $url;
            }

            if ($response->status() < 300 || $response->status() >= 400) {
                return $current; // final destination
            }

            $location = $this->absoluteUrl($current, (string) $response->header('Location'));

            if ($location === null || ! $this->isPublicHttpUrl($location)) {
                return $current; // refuse to follow into a non-public / invalid target
            }

            $current = $location;
        }

        return $current;
    }

    private function absoluteUrl(string $base, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $prefix = "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');

        return $prefix.(str_starts_with($location, '/') ? $location : "/{$location}");
    }

    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || in_array($host, ['localhost', 'metadata.google.internal'], true)) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_merge(gethostbynamel($host) ?: [], $this->aaaa($host));

        if ($ips === []) {
            return false; // unresolvable → refuse
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false; // private / reserved / loopback / link-local
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function aaaa(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $r): ?string => isset($r['ipv6']) && is_string($r['ipv6']) ? $r['ipv6'] : null,
            $records,
        )));
    }

    private function stripTracking(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        foreach (self::TRACKING_PARAMS as $param) {
            unset($query[$param]);
        }

        $parts['query'] = http_build_query($query);

        return $this->rebuild($parts);
    }

    private function externalId(Platform $platform, string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match ($platform) {
            Platform::Instagram => $this->match('#/(?:reel|reels|p|tv)/([^/?]+)#', $path),
            Platform::X => $this->match('#/status/(\d+)#', $path),
            Platform::Tiktok => $this->match('#/video/(\d+)#', $path) ?? $this->match('#/(\d{6,})#', $path),
            Platform::Youtube => $this->youtubeId($url, $path),
        };
    }

    private function youtubeId(string $url, string $path): ?string
    {
        if (str_contains((string) parse_url($url, PHP_URL_HOST), 'youtu.be')) {
            return $this->match('#/([^/?]+)#', $path);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query['v'] ?? null) ? $query['v'] : $this->match('#/shorts/([^/?]+)#', $path);
    }

    private function match(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function rebuild(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = ($parts['query'] ?? '') !== '' ? '?'.$parts['query'] : '';

        return $scheme.$host.$port.$path.$query;
    }
}
