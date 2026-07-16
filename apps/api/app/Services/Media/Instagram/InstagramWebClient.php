<?php

namespace App\Services\Media\Instagram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The shared Instagram web-API transport (T-075). Extracted from
 * InstagramApiResolver so both the carousel-image resolver (`mediaInfo`) and the
 * venue-profile locator (`profile`) reuse ONE copy of the auth/SSRF plumbing:
 * the session-cookie header (built from a Netscape cookies.txt), the required
 * `x-ig-app-id`, `allow_redirects=false` (an expired-cookie 302→/login must not
 * be followed to a 200 HTML page), a bounded timeout, and the never-throws
 * contract (any transport error → null, so callers fall through instead of
 * failing their job). Auth is required: without a readable cookie the endpoints
 * 302 to login, so `ready()` is false and every call returns null.
 */
class InstagramWebClient
{
    /**
     * The public web app id the Instagram web client sends. The media/profile
     * endpoints require it — without the header they redirect to the login page
     * instead of returning JSON.
     */
    private const APP_ID = '936619743392459';

    public function __construct(
        private readonly ?string $cookiesPath = null,
        private readonly int $timeout = 15,
        private readonly bool $enabled = true,
    ) {}

    /** True when this client can authenticate (enabled + a readable session cookie). */
    public function ready(): bool
    {
        return $this->enabled && $this->cookieHeader() !== null;
    }

    /**
     * A post's media info (`/media/{pk}/info/`) — the carousel/image payload. `$pk`
     * is a numeric media id the caller derived from a validated shortcode.
     *
     * @return array<string, mixed>|null
     */
    public function mediaInfo(string $pk): ?array
    {
        return $this->getJson("https://www.instagram.com/api/v1/media/{$pk}/info/");
    }

    /**
     * A user's public web profile (`/users/web_profile_info/`) → the `data.user`
     * node (biography, business_address_json, external_url, full_name, …). The
     * handle is validated against the IG username alphabet before it reaches the
     * query string — it can never inject a parameter or path.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $handle): ?array
    {
        $handle = ltrim(trim($handle), '@');
        if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) !== 1) {
            return null;
        }

        $json = $this->getJson('https://www.instagram.com/api/v1/users/web_profile_info/?username='.urlencode($handle));

        $user = $json['data']['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    /**
     * An authenticated GET returning decoded JSON, or null on any non-success —
     * this method NEVER throws (callers treat a throw as fatal). A 4xx here is the
     * signal the cookie needs a refresh.
     *
     * @return array<string, mixed>|null
     */
    private function getJson(string $url): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        // No session → can't authenticate; skip rather than 302 to /login.
        $cookie = $this->cookieHeader();
        if ($cookie === null) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withOptions(['allow_redirects' => false]) // an expired-cookie 302 to /login must not be followed to a 200 HTML page
                ->withHeaders([
                    'x-ig-app-id' => self::APP_ID,
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                    'Cookie' => $cookie,
                ])
                ->get($url);
        } catch (\Throwable $e) {
            // Never throw — a transport error just makes the caller fall through.
            Log::debug('instagram_web.request_threw', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            // Expired cookie, rate limit, or a removed resource — a 4xx is the
            // signal the cookie needs refresh. Not fatal: return null.
            Log::debug('instagram_web.request_failed', ['status' => $response->status()]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Build a `Cookie:` header from the configured Netscape cookies.txt. Returns
     * null when no readable file is set. Handles the `#HttpOnly_` line prefix a
     * browser export uses for HttpOnly cookies (e.g. `sessionid`) — dropping those
     * would strip the very cookie that authenticates.
     */
    private function cookieHeader(): ?string
    {
        if ($this->cookiesPath === null || ! is_file($this->cookiesPath) || ! is_readable($this->cookiesPath)) {
            return null;
        }

        $lines = file($this->cookiesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        $pairs = [];
        foreach ($lines as $line) {
            // FILE_IGNORE_NEW_LINES strips \n but not \r — a CRLF export (common
            // on Windows/browser extensions) would otherwise leave a trailing \r
            // on the cookie value and silently corrupt the Cookie header.
            $line = rtrim($line, "\r");

            // Keep `#HttpOnly_` rows (real cookies); skip genuine comment lines.
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
            } elseif ($line === '' || $line[0] === '#') {
                continue;
            }

            $cols = explode("\t", $line);
            if (count($cols) >= 7 && $cols[5] !== '' && $cols[6] !== '') {
                $pairs[] = $cols[5].'='.$cols[6];
            }
        }

        return $pairs === [] ? null : implode('; ', $pairs);
    }
}
