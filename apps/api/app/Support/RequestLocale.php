<?php

namespace App\Support;

use App\Http\Resources\TagResource;
use Illuminate\Http\Request;

/**
 * Resolves the display locale for a request (ADR-084 #2): an explicit `?locale=`
 * wins, then the first supported `Accept-Language`, else the app default. Tags
 * are stored in English and localized on output, so this decides which
 * `name_i18n` label {@see TagResource} emits.
 */
final class RequestLocale
{
    /** @var list<string> */
    public const SUPPORTED = ['es', 'en'];

    public static function resolve(Request $request): string
    {
        $param = self::normalize((string) $request->query('locale', ''));
        if ($param !== null) {
            return $param;
        }

        // Accept-Language, honoring the q-weights (highest wins, default 1.0).
        $ranked = [];
        foreach (explode(',', (string) $request->header('Accept-Language', '')) as $part) {
            $bits = explode(';', $part);
            $locale = self::normalize($bits[0]);
            if ($locale === null) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $bit) {
                if (preg_match('/^\s*q=([0-9.]+)/i', $bit, $m)) {
                    $q = (float) $m[1];
                }
            }
            $ranked[$locale] = max($ranked[$locale] ?? 0.0, $q);
        }
        if ($ranked !== []) {
            arsort($ranked);

            return (string) array_key_first($ranked);
        }

        return self::normalize((string) config('app.locale')) ?? 'en';
    }

    private static function normalize(string $tag): ?string
    {
        // Lowercase and drop any region subtag ("es-419" → "es"), then allowlist.
        $tag = mb_strtolower(trim(explode('-', trim($tag))[0]));

        return in_array($tag, self::SUPPORTED, true) ? $tag : null;
    }
}
