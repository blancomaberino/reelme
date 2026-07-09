<?php

namespace App\Services\Ingestion;

use App\Adapters\AdapterRegistry;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Turns a raw shared URL into a CanonicalUrl: expands shortlinks (following a
 * bounded number of redirects), strips tracking params, and extracts the
 * platform post id. Used by both POST /shares (duplicate guard) and IngestShare
 * so both agree on the canonical form.
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

    private function expand(string $url): string
    {
        try {
            $response = Http::timeout(5)
                ->withOptions(['allow_redirects' => ['max' => 3, 'track_redirects' => true]])
                ->get($url);

            $chain = $response->handlerStats()['redirect_url'] ?? null;
            $effective = $response->effectiveUri();

            return $chain ?? ($effective !== null ? (string) $effective : $url);
        } catch (ConnectionException) {
            return $url; // fall back to the shortlink; the adapter chain still resolves
        }
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
