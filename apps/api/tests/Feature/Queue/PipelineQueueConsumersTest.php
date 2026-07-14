<?php

use App\Jobs\IngestShare;
use App\Jobs\Pipeline;

/**
 * Guards against the class of bug where a pipeline stage dispatches to a queue
 * name that no Horizon supervisor consumes — the job then sits in Redis forever,
 * the share never leaves its non-terminal status, and (because the job never
 * runs) its failed() hook never fires. HorizonConfigTest only checks the
 * supervisors' own queue set is canonical; it can't see the jobs' target queues.
 */
it('dispatches every pipeline stage to a queue a supervisor consumes', function () {
    $consumed = collect(config('horizon.defaults'))
        ->flatMap(fn (array $s) => $s['queue'])
        ->unique()
        ->all();

    $jobs = array_merge(
        [new IngestShare(1)],
        Pipeline::chain(1),
    );

    // Collect offenders so a failure names the exact stage(s) — this is the bug
    // the fix closes (ExtractPlaceData et al. on the unconsumed `analysis` queue).
    $violations = [];
    foreach ($jobs as $job) {
        $queue = $job->queue; // set via onQueue() in each job's constructor
        if (! is_string($queue) || ! in_array($queue, $consumed, true)) {
            $violations[] = class_basename($job).' → '.var_export($queue, true);
        }
    }

    expect($violations)->toBe([]);
});
