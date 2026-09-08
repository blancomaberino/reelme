<?php

use App\Exceptions\AgeRestrictedException;
use App\Exceptions\Contracts\ApiError;
use Illuminate\Support\Collection;

/**
 * The rule that replaced the list (T-156).
 *
 * `ApiExceptionRenderer` named each domain exception in its `match`, and
 * `bootstrap/app.php` kept a second list to decide what was worth reporting.
 * The two drifted, and the drift published a false privacy claim. Both now ask
 * the `ApiError` contract — which only works while every exception written to
 * that shape actually declares it. This is that guarantee: an exception with a
 * `status()` that forgets `implements ApiError` renders as a 500 and reports as
 * a fault, which is precisely the bug wearing a new class name.
 *
 * Framework-free (it reads the directory, not the container) so it stays in
 * Unit, where this suite keeps tests that need no application boot.
 */
function exceptionClasses(): Collection
{
    $directory = dirname(__DIR__, 3).'/app/Exceptions';

    return collect(glob($directory.'/{,*/}*.php', GLOB_BRACE) ?: [])
        ->map(fn (string $path) => 'App\\Exceptions\\'.str_replace(
            ['/', '.php'], ['\\', ''], ltrim(substr($path, strlen($directory)), '/')
        ))
        ->filter(fn (string $class) => class_exists($class) || interface_exists($class));
}

it('requires every exception that states a status to declare the contract', function () {
    $missing = exceptionClasses()
        ->filter(fn (string $class) => is_subclass_of($class, Throwable::class))
        ->filter(fn (string $class) => (new ReflectionClass($class))->hasMethod('status'))
        ->reject(fn (string $class) => is_subclass_of($class, ApiError::class))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('finds the exceptions it is meant to be checking', function () {
    // A scan that silently matched nothing would pass forever, so anchor it —
    // but on the SCAN, not on a count. An exact number would re-create the
    // registration step this interface exists to delete: the seventh domain
    // exception is supposed to need no edit anywhere, and `expected 7 to be 6`
    // is that edit wearing a different hat.
    //
    // Two anchors, one per thing that can break: a nested file (the interface
    // itself lives in Contracts/) and a top-level one.
    expect(exceptionClasses())
        ->toContain(ApiError::class)
        ->toContain(AgeRestrictedException::class)
        ->and(exceptionClasses()->filter(fn (string $class) => is_subclass_of($class, ApiError::class)))
        ->not->toBeEmpty();
});
