<?php

use Illuminate\Support\Facades\File;

/**
 * The privacy policy makes a claim about server logs. This is what keeps it
 * true (T-156).
 *
 * The policy tells people their coordinates "may appear in our server logs for
 * a limited time before they are discarded" — a positive statement, not a
 * hedge. Laravel's shipped default for the `stack` channel is `single`, which
 * writes ONE file that grows forever and discards nothing, so on a default
 * deployment that sentence was false the moment it was published.
 *
 * A privacy policy is not documentation of an intention; it is a statement
 * about what the system does. So the configuration that makes it true is
 * asserted here rather than left to whoever writes the next `.env`.
 */
it('defaults to a log channel that actually discards, because the privacy policy says so', function () {
    // Asserted at the DEFAULT, not at the effective value. A developer's own
    // `.env` may well pin `LOG_STACK=single` — this repo's does — and that is
    // their business; what the policy commits to is what a deployment gets when
    // nobody has chosen otherwise. Reading the fallback out of the config source
    // is the only way to see it, since `env()` has already resolved by the time
    // the container is up.
    $source = (string) File::get(config_path('logging.php'));

    expect($source)->toMatch("/env\('LOG_STACK', 'daily'\)/");

    // And the channel that default names must genuinely prune.
    expect(config('logging.channels.daily.days'))->toBeGreaterThan(0);
})->group('privacy');

it('keeps the shipped example in step with the config default', function () {
    // A default nobody deploys is not a commitment. `.env.example` is what a new
    // environment is built from, so it must not quietly reinstate `single`.
    $example = (string) File::get(base_path('.env.example'));

    expect($example)->toMatch('/^LOG_STACK=daily$/m')
        ->and($example)->toMatch('/^LOG_DAILY_DAYS=\d+$/m');
});

it('says in the policy what the configuration does', function (string $view, string $claim) {
    // The pairing is the point: if someone softens the policy sentence, the
    // config assertions above stop describing a promise anyone made, and this
    // test is where that is noticed.
    expect((string) File::get(resource_path("views/legal/privacy/{$view}.blade.php")))->toContain($claim);
})->with([
    ['en', 'before they are discarded'],
    ['es', 'antes de que se descarten'],
]);
