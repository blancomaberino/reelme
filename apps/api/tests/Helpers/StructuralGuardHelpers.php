<?php

use Illuminate\Support\Facades\File;

/**
 * Shared machinery for the "no unguarded writer" structural guards.
 *
 * Two of them exist — `dishes` (T-157) and `place_open_periods` (T-158) — and
 * both ask the same question: does any code write the column an observer
 * derives from, by a route that fires no model events? The DETECTORS differ
 * (each knows its own table and its own spellings) and stay in their test
 * files; the comment stripper and the repo walk are the same job twice and live
 * here.
 *
 * The stripper matters more than it looks. T-158 shipped a regex version
 * (`!/\*.*?\*​/|//[^\n]*!s`) which also strips inside STRING LITERALS — a line
 * holding `'https://example.test/places'` lost everything after the `//`, which
 * could hide a real writer sharing that line. `token_get_all` cannot make that
 * mistake, and it was already written for the dishes guard.
 */
if (! function_exists('stripPhpComments')) {
    function stripPhpComments(string $code): string
    {
        // `token_get_all` treats anything before an opening tag as inline HTML,
        // so a bare code fragment (what the control fixtures pass) would come
        // back untouched — comments included — and the negative controls would
        // flag.
        $prefixed = str_contains($code, '<?php') ? $code : "<?php\n".$code;

        $out = '';
        foreach (@token_get_all($prefixed) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}

if (! function_exists('unknownWritersOf')) {
    /**
     * Every `app/` or `database/` file the detector calls a writer that the
     * allow-list does not name.
     *
     * `database/` as well as `app/`: seeders and data migrations write these
     * tables too, and a guard scanning only `app_path()` would let a migration
     * rewrite every row in silence.
     *
     * Keyed on the path RELATIVE TO THE REPO rather than the basename — keying
     * on the filename let a brand-new `Api/V2/MePlacesController.php` inherit
     * the V1 exemption for free.
     *
     * @param  array<string, string>  $known  relative path => why it is allowed
     * @param  callable(string): bool  $detects  given comment-stripped source, is this a writer?
     * @return list<string>
     */
    function unknownWritersOf(array $known, callable $detects): array
    {
        $offenders = [];

        foreach ([app_path(), base_path('database')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(base_path().'/', '', $file->getPathname());

                if ($detects(stripPhpComments((string) file_get_contents($file->getPathname())))
                    && ! array_key_exists($relative, $known)) {
                    $offenders[] = $relative;
                }
            }
        }

        return $offenders;
    }
}
