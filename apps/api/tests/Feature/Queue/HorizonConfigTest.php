<?php

use App\Providers\HorizonServiceProvider;
use Laravel\Horizon\Horizon;

/*
 * Horizon's notification routing is STATIC, so anything these tests set leaks
 * into every test that runs after them in the same process — including ones
 * that would then look like they had an alert destination configured. Restored
 * explicitly rather than trusted to be overwritten: `routeSlackNotificationsTo`
 * sets the channel even when the webhook is null, so a partial reset is not one.
 */
afterEach(function () {
    Horizon::$email = null;
    Horizon::$slackWebhookUrl = null;
    Horizon::$slackChannel = null;
    Horizon::$smsNumber = null;
});

it('assigns exactly the canonical queue set across supervisors', function () {
    $supervisors = config('horizon.defaults');

    $queues = collect($supervisors)
        ->flatMap(fn (array $s) => $s['queue'])
        ->unique()
        ->sort()
        ->values()
        ->all();

    // Canonical set per 04-analysis-pipeline §1, plus `housekeeping` (T-050 —
    // GDPR purge/export). `payouts` is intentionally absent until M4 (T-045).
    // Guards against later queue-name drift.
    expect($queues)->toBe([
        'analyze', 'default', 'fetch', 'housekeeping', 'ingest', 'media',
        'notifications', 'publish', 'resolve', 'transcribe',
    ]);
});

it('runs every supervisor in every environment', function () {
    // A supervisor defined only in `defaults` never starts: Horizon builds its
    // provisioning plan from `environments` and merges the defaults in. Omitting
    // one is silent — jobs enqueue onto a queue nothing is listening to, and the
    // only symptom is work that never happens.
    $defined = array_keys(config('horizon.defaults'));

    foreach (config('horizon.environments') as $environment => $supervisors) {
        expect(array_keys($supervisors))->toEqualCanonicalizing(
            $defined,
            "environment {$environment} must run every defined supervisor",
        );
    }
});

it('keeps every supervisor timeout below the redis retry_after', function () {
    $retryAfter = config('queue.connections.redis.retry_after');

    foreach (config('horizon.defaults') as $name => $supervisor) {
        expect($supervisor['timeout'])->toBeLessThan(
            $retryAfter,
            "supervisor {$name} timeout must be < retry_after ({$retryAfter})",
        );
    }
});

it('sets a long-wait threshold for every queue that runs long jobs', function () {
    // A queue with no entry falls back to `redis:default` (60s). For the
    // supervisors whose jobs legitimately take minutes, that turns every normal
    // run into a backlog alert — and an alert that always fires is one nobody
    // reads.
    $waits = config('horizon.waits');
    $long = ['media', 'transcribe', 'analyze', 'resolve', 'publish', 'housekeeping'];

    foreach ($long as $queue) {
        expect($waits)->toHaveKey("redis:{$queue}");
        expect($waits["redis:{$queue}"])->toBeGreaterThanOrEqual(
            config('horizon.defaults.supervisor-'.match ($queue) {
                'media', 'transcribe' => 'media',
                'analyze', 'resolve', 'publish' => 'analyze',
                default => 'housekeeping',
            }.'.timeout'),
            "redis:{$queue} must not alert before its own jobs can finish",
        );
    }
});

it('actually routes the long-wait alert somewhere when configured', function () {
    // The thresholds above have been tuned since T-028 and, until T-052, the
    // notification they raise went NOWHERE: Horizon routes nothing by default.
    // The whole alerting half of this config was dead — correct thresholds, a
    // firing alert, and no human on the other end, which is indistinguishable
    // from having no thresholds at all. This asserts the wiring, not the value.
    config([
        'horizon.notifications.mail' => 'ops@reelmap.test',
        'horizon.notifications.slack_webhook' => 'https://hooks.slack.test/x',
        'horizon.notifications.slack_channel' => '#pipeline',
    ]);

    (new HorizonServiceProvider(app()))->boot();

    expect(Horizon::$email)->toBe('ops@reelmap.test')
        ->and(Horizon::$slackWebhookUrl)->toBe('https://hooks.slack.test/x')
        ->and(Horizon::$slackChannel)->toBe('#pipeline');
});

it('stays silent when no destination is configured', function () {
    // Local and CI. An alert that pages during a test run is an alert somebody
    // turns off — and then it is off in production too.
    config(['horizon.notifications' => ['mail' => null, 'slack_webhook' => null, 'slack_channel' => '#alerts']]);
    Horizon::$email = null;
    Horizon::$slackWebhookUrl = null;

    (new HorizonServiceProvider(app()))->boot();

    expect(Horizon::$email)->toBeNull()
        ->and(Horizon::$slackWebhookUrl)->toBeNull();
});
