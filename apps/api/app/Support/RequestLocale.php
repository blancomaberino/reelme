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

        foreach (explode(',', (string) $request->header('Accept-Language', '')) as $part) {
            // Drop the ";q=" weight and any region subtag ("es-419" → "es").
            $tag = self::normalize(explode('-', trim(explode(';', $part)[0]))[0]);
            if ($tag !== null) {
                return $tag;
            }
        }

        return self::normalize((string) config('app.locale')) ?? 'en';
    }

    private static function normalize(string $tag): ?string
    {
        $tag = mb_strtolower(trim($tag));

        return in_array($tag, self::SUPPORTED, true) ? $tag : null;
    }
}
