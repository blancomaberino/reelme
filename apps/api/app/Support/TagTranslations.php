<?php

namespace App\Support;

/**
 * The seed dictionary of Spanish tag translations (ADR-084 #2), ported from the
 * mobile client's static map. It covers the controlled vocabulary (cuisines /
 * vibes / diets) and common dishes; anything unlisted has no translation and
 * falls back to the canonical English name. This is the SEED for `tags.name_i18n`
 * — once AI translate-on-create (#4) lands, new tags get their own translations
 * and this dictionary only backfills history.
 */
final class TagTranslations
{
    /** @var array<string, string>|null */
    private static ?array $es = null;

    /**
     * Spanish translation for a tag name, or null when unlisted. Lookup is by the
     * lowercased, trimmed name (the same key the mobile dictionary used).
     */
    public static function es(string $name): ?string
    {
        self::$es ??= require __DIR__.'/../../database/seeders/data/tag_es_translations.php';

        return self::$es[mb_strtolower(trim($name))] ?? null;
    }

    /**
     * The `name_i18n` map to persist for a freshly-created tag, or null when the
     * name has no known translation (leave the column null → English fallback).
     *
     * @return array<string, string>|null
     */
    public static function forName(string $name): ?array
    {
        $es = self::es($name);

        return $es !== null ? ['es' => $es] : null;
    }
}
