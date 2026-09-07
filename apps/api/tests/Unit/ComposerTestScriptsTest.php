<?php

/**
 * Pins the invariants of the `test` / `test:coverage` composer scripts.
 *
 * Both are hand-maintained near-copies: three entries, differing only in the
 * Pest flags and the time bound. Composer script arrays have no composition
 * primitive that survives this file's constraints — a delegating `@test` entry
 * would re-propagate `--` arguments into every entry of the delegated script,
 * which is the exact bug `@no_additional_args` exists to stop. So the
 * duplication is deliberate, and this is what stops it drifting: raise the
 * bound on one and not the other, or drop a guard from one, and nothing else
 * in the project would notice.
 *
 * Most of these assertions correspond to a defect that actually occurred on the
 * branch that added these bounds, caught by review rather than by a gate; the
 * rest (exactly-one-Pest-entry, the setup-entry identity pin) are prophylactic.
 */
it('keeps both test scripts bounded, guarded, and in step', function () {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode(
        // Unit tests here are framework-free by design (see tests/Pest.php),
        // so no base_path() — this file is apps/api/tests/Unit/.
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $test = $composer['scripts']['test'];
    $coverage = $composer['scripts']['test:coverage'];

    // The setup half must be IDENTICAL, not merely similar: it is the entry
    // that stops a stale config cache pointing RefreshDatabase's migrate:fresh
    // at the dev database, and a divergence would apply that protection to one
    // command and not the other.
    expect($coverage[0])->toBe($test[0])
        ->and($coverage[1])->toBe($test[1]);

    // `disableProcessTimeout` is process-wide, so it removes composer's 300s
    // default from EVERY entry — which is why each entry carries its own bound.
    expect($test[0])->toBe('Composer\\Config::disableProcessTimeout');

    foreach (['test' => $test, 'test:coverage' => $coverage] as $name => $script) {
        foreach (array_slice($script, 1) as $i => $entry) {
            // $i counts from the sliced array, so name the composer.json index.
            $at = $i + 1;
            expect($entry)->toMatch('/^timeout -k \d+s \d+ /',
                "every command entry of `$name` needs its own time bound (entry $at)");
        }
    }

    // The guard that keeps `composer test -- --coverage` from landing its flags
    // on artisan, where it exits 1 before Pest ever starts.
    expect($test[1])->toContain('@no_additional_args');

    // Find the Pest entry by CONTENT, never by index. Pinning it to $script[2]
    // keeps passing if a command is inserted BETWEEN config:clear and Pest: that
    // entry's 60s bound satisfies the ceiling below, the suite bound goes
    // unchecked, and the coverage-vs-plain comparison compares two setup
    // entries. A false PASS in the test written to stop false passes. (An entry
    // inserted before config:clear cannot do it — the identity pin above
    // catches that one.)
    $pestBound = function (array $script, string $name): int {
        $entries = array_values(array_filter(
            $script,
            fn ($entry) => is_string($entry) && str_contains($entry, 'vendor/bin/pest'),
        ));

        expect($entries)->toHaveCount(1, "`$name` must run Pest exactly once");
        expect($entries[0])->toMatch('/^timeout -k \d+s \d+ /',
            "`$name`'s Pest entry must carry its own time bound");

        preg_match('/^timeout -k \d+s (\d+) /', $entries[0], $m);

        return (int) $m[1];
    };

    // The suite bound must stay under what CI would actually allow, which is
    // NOT the job's 15 minutes: `ci.yml`'s api job spends ~130s on checkout,
    // setup-php, an apt ffmpeg install, composer install, lint, stan and migrate
    // before Pest starts (measured across three runs: 268-285s total, 149-155s
    // in the Pest step). So the residual is ~770s, and a local bound above it
    // would pass here and be killed by GitHub — which emits no 124, no 137 and
    // no summary line, i.e. the one kill shape this project cannot read. 750
    // keeps a margin against that overhead drifting.
    $plain = $pestBound($test, 'test');
    $instrumented = $pestBound($coverage, 'test:coverage');

    expect($plain)->toBeLessThanOrEqual(750)
        ->and($instrumented)->toBeGreaterThan($plain);

    // Coverage must actually measure coverage. Without this the flag can be
    // dropped and every other assertion here still passes — the script would
    // simply be a slower duplicate of `test`, and CLAUDE.md mandates it.
    $coverageEntry = array_values(array_filter(
        $coverage,
        fn ($entry) => is_string($entry) && str_contains($entry, 'vendor/bin/pest'),
    ))[0];
    expect($coverageEntry)->toContain('--coverage');
    expect($test[count($test) - 1])->not->toContain('--coverage');
});
