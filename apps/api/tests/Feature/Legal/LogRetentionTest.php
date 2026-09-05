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
    // The RESOLVED default, not a substring of the config source.
    //
    // The first version matched `env('LOG_STACK', 'daily')` anywhere in
    // `config/logging.php` — which a COMMENT satisfies. Delete the code and
    // leave the prose (that file is now full of it) and the test stayed green
    // while the default reverted to Laravel's unbounded `single`. It would also
    // have gone red on a Pint reformat with behaviour unchanged.
    //
    // So: clear any override the way a fresh deployment has none, re-evaluate
    // the config file, and read what a box nobody configured actually gets.
    // That is the thing the policy sentence commits to, and it is the same
    // answer locally (where this repo's own `.env` pins `single`) and in CI.
    $saved = getenv('LOG_STACK');
    putenv('LOG_STACK');
    unset($_ENV['LOG_STACK'], $_SERVER['LOG_STACK']);

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path('logging.php');
        /** @var list<string> $channels */
        $channels = $config['channels']['stack']['channels'];
    } finally {
        if ($saved !== false) {
            putenv('LOG_STACK='.$saved);
            $_ENV['LOG_STACK'] = $saved;
            $_SERVER['LOG_STACK'] = $saved;
        }
    }

    expect($channels)->not->toBeEmpty();

    foreach ($channels as $name) {
        $channel = config("logging.channels.{$name}");

        expect($channel)->not->toBeNull("the default stack names an undefined channel [{$name}]");

        // Prune on a schedule, or store nothing locally. `single` is neither —
        // it is one file that grows forever, and it is the default this config
        // deliberately overrides.
        $prunes = ($channel['days'] ?? 0) > 0;
        $storesNothing = in_array($channel['driver'] ?? '', ['null', 'stderr', 'syslog', 'errorlog'], true);

        expect($prunes || $storesNothing)->toBeTrue(
            "the DEFAULT logging channel [{$name}] neither prunes nor is a pass-through, so request data "
            .'would be kept indefinitely — contradicting the retention sentence in the privacy policy.'
        );
    }
});

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

it('states the retention period the configuration actually keeps', function (string $view, string $claim) {
    // The policy used to say logs are discarded "after a limited time" with no
    // period, and the retention section listed five classes without this one —
    // which an SEO/compliance read flagged as the single easiest thing for a
    // data-protection authority or an app-store privacy review to pick up.
    //
    // It can state 14 days now because the configuration keeps 14 days: the
    // number here and `LOG_DAILY_DAYS` are the same commitment, and the test
    // above is what stops the config drifting away from it.
    expect((string) File::get(resource_path("views/legal/privacy/{$view}.blade.php")))->toContain($claim);
})->with([
    ['en', '<strong>Server logs:</strong> 14 days'],
    ['es', '<strong>Registros del servidor:</strong> 14 días'],
]);

it('quotes the same number in the policy as the config prunes at', function () {
    // Two literals, one promise. If someone lowers LOG_DAILY_DAYS to 7 for disk
    // reasons, the page keeps promising 14 and is wrong; this is where that is
    // noticed rather than in a complaint.
    $days = (int) config('logging.channels.daily.days');

    expect($days)->toBeGreaterThan(0)
        ->and((string) File::get(resource_path('views/legal/privacy/en.blade.php')))
        ->toContain("<strong>Server logs:</strong> {$days} days")
        ->and((string) File::get(resource_path('views/legal/privacy/es.blade.php')))
        ->toContain("<strong>Registros del servidor:</strong> {$days} días");
});

it('does not call a ~10 metre fix "approximate", which is a coarser tier', function (string $view, string $purpose) {
    // 10m is PRECISE location under Apple's and Google's definitions — their
    // coarse tier is 1-3 km. The purposes table reused "approximate" from the
    // rows above, which made it disagree with the Location bullet below and
    // would have been the evidence against a store label claiming coarse.
    //
    // The whole <tr> is extracted, not a fixed-width window: the first version
    // read 400 characters from the purpose text, and the explanatory comment
    // inside the row is long enough that the cell fell outside it — so
    // reinstating "Approximate device location" left the test green.
    $source = (string) File::get(resource_path("views/legal/privacy/{$view}.blade.php"));

    expect(preg_match('/<tr>(?:(?!<\/tr>).)*'.preg_quote($purpose, '/').'.*?<\/tr>/s', $source, $m))->toBe(1);

    expect($m[0])->not->toContain('Approximate device location')
        ->and($m[0])->not->toContain('Ubicación aproximada del dispositivo')
        ->and($m[0])->toMatch('/10\s*(metres|metros)/');
})->with([
    ['en', 'Showing how far away each place is'],
    ['es', 'Mostrar a qué distancia queda cada lugar'],
]);
