<?php

namespace App\Services\Media;

use App\Services\Http\PublicUrlGuard;
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

    /**
     * https only, and (in non-test envs) a public host — blocks SSRF to internal
     * IPs. Delegates to the shared {@see PublicUrlGuard}, whose UnsafeUrlException
     * is a RuntimeException and already carries "…https"/"…public address"
     * wording. DNS resolution hits the network, so it is skipped under the
     * no-network test env (toggle via MEDIA_VERIFY_IMAGE_HOST); production keeps it.
     */
    private function assertFetchable(string $url): void
    {
        (new PublicUrlGuard)->assertPublic(
            $url,
            allowedSchemes: ['https'],
            verifyHost: (bool) config('media.verify_image_host', true),
        );
    }
}
