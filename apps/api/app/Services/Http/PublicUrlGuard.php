<?php

namespace App\Services\Http;

/**
 * The one SSRF vetting for any user/place-derived URL the app fetches: an allowed
 * scheme, a real host, and — unless disabled for the no-network test env — a host
 * whose EVERY resolved address (A + AAAA) is public. Callers must also disable
 * redirect-following, since this only vets the URL they hand in (a 30x could
 * bounce to an internal address like the cloud metadata endpoint).
 */
class PublicUrlGuard
{
    /**
     * @param  list<string>  $allowedSchemes  lowercase schemes, e.g. ['https'] or ['http', 'https']
     * @param  bool  $verifyHost  resolve + vet the host (skip only in the no-network test env)
     *
     * @throws UnsafeUrlException when the URL is not safe to fetch
     */
    public function assertPublic(string $url, array $allowedSchemes = ['https'], bool $verifyHost = true): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new UnsafeUrlException('url scheme must be one of: '.implode(', ', $allowedSchemes));
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            throw new UnsafeUrlException('url has no host');
        }

        if (! $verifyHost) {
            return;
        }

        // Validate EVERY address the host resolves to (A + AAAA), not just the
        // first: curl picks its own address, so a dual-stack host with a public A
        // but a private/loopback AAAA would otherwise slip through.
        foreach ($this->resolveAddresses($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new UnsafeUrlException('url host is not a public address');
            }
        }
    }

    /**
     * Whether the URL passes {@see assertPublic()} — the non-throwing form.
     *
     * @param  list<string>  $allowedSchemes
     */
    public function isPublic(string $url, array $allowedSchemes = ['https'], bool $verifyHost = true): bool
    {
        try {
            $this->assertPublic($url, $allowedSchemes, $verifyHost);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    /**
     * Every IP the host resolves to (IPv4 + IPv6). An IP literal returns itself.
     *
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        $host = trim($host, '[]'); // unwrap a bracketed IPv6 literal

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = array_merge(
            dns_get_record($host, DNS_A) ?: [],
            dns_get_record($host, DNS_AAAA) ?: [],
        );

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            throw new UnsafeUrlException('url host did not resolve');
        }

        return $ips;
    }
}
