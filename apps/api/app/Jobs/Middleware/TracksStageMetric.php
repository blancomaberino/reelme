<?php

namespace App\Jobs\Middleware;

use App\Jobs\Concerns\RecordsStageMetrics;
use Closure;
use Throwable;

/**
 * Job middleware that closes the stage metric a pipeline job opens (T-093):
 * marks it `completed` (with duration) when handle() returns, or `failed` when
 * it throws — then re-throws so the normal retry/failed() path still runs.
 *
 * Lives in middleware (not each job's handle) so the many early-return branches
 * of a stage still get their metric closed from one place. It no-ops for a job
 * that never opened a stage (see {@see RecordsStageMetrics}).
 * Only runs when a job is executed through the queue worker; a direct
 * ->handle() in a unit test bypasses middleware and leaves the row `running`,
 * exactly as before.
 */
class TracksStageMetric
{
    public function handle(object $job, Closure $next): mixed
    {
        try {
            $result = $next($job);

            if (method_exists($job, 'completeCurrentStage')) {
                $job->completeCurrentStage();
            }

            return $result;
        } catch (Throwable $e) {
            if (method_exists($job, 'failCurrentStage')) {
                $job->failCurrentStage($e);
            }

            throw $e;
        }
    }
}
