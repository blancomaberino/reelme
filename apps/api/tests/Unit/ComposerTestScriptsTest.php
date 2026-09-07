<?php

/**
 * Pins the invariants of the `test` / `test:coverage` composer scripts.
 *
 * Both are hand-maintained near-copies: four entries, differing only in the
 * Pest flags and the time bound. Composer script arrays have no composition
 * primitive that survives this file's constraints — a delegating `@test` entry
 * would re-propagate `--` arguments into every entry of the delegated script,
 * which is the exact bug `@no_additional_args` exists to stop. So the
 * duplication is deliberate, and this is what stops it drifting: raise the
 * bound on one and not the other, or drop a guard from one, and nothing else
 * in the project would notice.
 *
 * Every assertion here is a bug that actually shipped on the branch that added
 * these bounds, caught by review rather than by a gate.
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
            expect($entry)->toMatch('/^timeout -k \d+s \d+ /',
                "every command entry of `$name` needs its own time bound (entry $i)");
        }
    }

    // The guard that keeps `composer test -- --coverage` from landing its flags
    // on artisan, where it exits 1 before Pest ever starts.
    expect($test[1])->toContain('@no_additional_args');

    // The suite bound must stay under CI's budget; coverage is not run by CI
    // and is deliberately roomier.
    preg_match('/^timeout -k \d+s (\d+) /', $test[2], $plain);
    preg_match('/^timeout -k \d+s (\d+) /', $coverage[2], $instrumented);
    expect((int) $plain[1])->toBeLessThanOrEqual(750)
        ->and((int) $instrumented[1])->toBeGreaterThan((int) $plain[1]);
});
