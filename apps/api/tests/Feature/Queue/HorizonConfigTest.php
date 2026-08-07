<?php

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
