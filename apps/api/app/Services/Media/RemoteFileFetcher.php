<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Streams a remote https URL to a temp file with a hard byte cap (never buffers
 * the whole body) and a defensive SSRF guard (https only, public host). Used to
 * pull resolved post images before they're stored as keyframes (T-013).
 */
class RemoteFileFetcher
{
    /** Returns the temp file path; the caller owns cleanup. */
    public function fetchToTemp(string $url): string
    {
        $this->assertFetchable($url);

        $cap = (int) config('media.max_image_download_bytes', 25 * 1024 * 1024);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'img_');
        $out = fopen($tmp, 'wb');
        if (! is_resource($out)) {
            throw new RuntimeException('could not open temp file');
        }

        try {
            // No redirects: the SSRF guard only vetted THIS host, so a 30x to an
            // internal address (e.g. cloud metadata) must not be followed.
            $body = Http::timeout(60)
                ->withOptions(['stream' => true, 'allow_redirects' => false])
                ->get($url)->throw()->toPsrResponse()->getBody();
            $written = 0;
            while (! $body->eof()) {
                $chunk = $body->read(1_048_576);
                $written += strlen($chunk);
                if ($written > $cap) {
                    throw new RuntimeException('remote file exceeds the size cap');
                }
                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }

        fclose($out);

        return $tmp;
    }

    /** https only, and (in non-test envs) a public host — blocks SSRF to internal IPs. */
    private function assertFetchable(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new RuntimeException('remote url must be https');
        }

        // DNS resolution hits the network, so skip it under the no-network test
        // env (toggle via MEDIA_VERIFY_IMAGE_HOST); production keeps the guard.
        if (! (bool) config('media.verify_image_host', true)) {
            return;
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new RuntimeException('remote url has no host');
        }

        // Validate EVERY address the host resolves to (A + AAAA), not just the
        // first IPv4: curl picks its own address, so a dual-stack host with a
        // public A but a private/loopback AAAA would otherwise slip through.
        foreach ($this->resolveAddresses($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('remote url host is not a public address');
            }
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
            throw new RuntimeException('remote url host did not resolve');
        }

        return $ips;
    }
}
