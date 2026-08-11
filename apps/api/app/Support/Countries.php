<?php

namespace App\Support;

use Collator;
use Locale;

/**
 * ISO 3166-1 alpha-2 countries: the canonical allow-list, plus display names
 * localized through ICU (T-110).
 *
 * WHY THE LIST IS BUNDLED. `Locale::getDisplayRegion()` is a DISPLAY function
 * and never fails — it echoes whatever it does not know (`QQ` → "QQ", `U1` →
 * "U1") and answers "Región desconocida" for `ZZ`. So it cannot decide whether a
 * code is real, and validating with it would happily store `U1` on a user.
 * `ResourceBundle::create('en', 'ICUDATA-region')` is not an alternative either
 * — iterating it yields four entries. Hence the explicit table below, which is
 * also what `places.country_code` should have been validated against all along
 * (the Filament place form takes any two characters).
 */
final class Countries
{
    /**
     * Every officially assigned ISO 3166-1 alpha-2 code (249). User-assigned
     * (`AA`, `QM`–`QZ`, `XA`–`XZ`, `ZZ`), exceptionally reserved (`EU`, `UK`)
     * and transitional codes are deliberately absent: they are not countries,
     * and `ZZ` in particular is ICU's "unknown region" sentinel.
     *
     * @var list<string>
     */
    public const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ',
        'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW',
        'CX', 'CY', 'CZ',
        'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ',
        'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET',
        'FI', 'FJ', 'FK', 'FM', 'FO', 'FR',
        'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT',
        'GU', 'GW', 'GY',
        'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT',
        'JE', 'JM', 'JO', 'JP',
        'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
        'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
        'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS',
        'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
        'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ',
        'OM',
        'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY',
        'QA',
        'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
        'ST', 'SV', 'SX', 'SY', 'SZ',
        'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
        'UA', 'UG', 'UM', 'US', 'UY', 'UZ',
        'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU',
        'WF', 'WS',
        'YE', 'YT',
        'ZA', 'ZM', 'ZW',
    ];

    /**
     * Localized catalogs, memoized per locale for the life of the request.
     *
     * @var array<string, list<array{code: string, name: string}>>
     */
    private static array $catalogs = [];

    /**
     * Uppercase a client-supplied code so `uy` and `UY` are the same country.
     * Says nothing about validity — {@see isValid()} decides that.
     */
    public static function normalize(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : mb_strtoupper($code);
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && in_array($code, self::CODES, true);
    }

    /**
     * The country's name in `$locale` — "Uruguay"/"Uruguay", "ES" → "Spain"/"España".
     *
     * Null for anything not in {@see CODES}, precisely because ICU would answer
     * with the input itself and a bogus code would render as a plausible name.
     */
    public static function name(?string $code, string $locale): ?string
    {
        if (! self::isValid($code)) {
            return null;
        }

        // A leading "-" makes the tag region-only ("-UY"), so ICU reads UY as the
        // region rather than as a language.
        return Locale::getDisplayRegion('-'.$code, $locale);
    }

    /**
     * Every country as `{code, name}`, sorted by name in the given locale.
     *
     * Sorted with a Collator, not `sort()`: byte order puts "Åland"/"Zimbabwe"
     * after every unaccented name, and Spanish readers expect "Angola" next to
     * "Antigua". ext-intl is a hard requirement rather than a soft one — the
     * names on the line above come from `Locale::getDisplayRegion()`, so a
     * fallback here could never run.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function catalog(string $locale): array
    {
        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $rows = array_map(
            fn (string $code): array => ['code' => $code, 'name' => (string) self::name($code, $locale)],
            self::CODES,
        );

        $collator = new Collator($locale);
        usort($rows, fn (array $a, array $b): int => (int) $collator->compare($a['name'], $b['name']));

        return self::$catalogs[$locale] = $rows;
    }
}
