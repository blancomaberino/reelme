<?php

it('assigns exactly the canonical queue set across supervisors', function () {
    $supervisors = config('horizon.defaults');

    $queues = collect($supervisors)
        ->flatMap(fn (array $s) => $s['queue'])
        ->unique()
        ->sort()
        ->values()
        ->all();

    // Canonical set per 04-analysis-pipeline §1. `payouts` is intentionally
    // absent until M4 (T-045). Guards against later queue-name drift.
    expect($queues)->toBe([
        'analyze', 'default', 'fetch', 'ingest', 'media',
        'notifications', 'publish', 'resolve', 'transcribe',
    ]);
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
