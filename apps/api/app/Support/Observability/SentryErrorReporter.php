<?php

namespace App\Support\Observability;

use Sentry\State\Scope;
use Throwable;

use function Sentry\captureException;
use function Sentry\withScope;

/**
 * Forwards captured exceptions to Sentry with the correlation context (T-052).
 *
 * The context goes on a SCOPE rather than as `extra` on the event: scoped tags
 * are searchable and groupable in the Sentry UI, so "every failure for share
 * 8412" or "everything from request_id X" is one query. As `extra` the same
 * data is only visible once you already have the right event open, which is the
 * wrong way round — the correlation exists to help you FIND the event.
 *
 * `share_id`, `request_id`, `queue` and `job` become tags (low-cardinality
 * enough to index, and the ones you actually filter by). Anything else the
 * caller passes rides along as context.
 *
 * Never throws: telemetry that can take down the thing it is watching is worse
 * than no telemetry. The whole body is wrapped, including the scope callback —
 * a misconfigured DSN raises at capture time, not at construction.
 */
class SentryErrorReporter implements ErrorReporter
{
    /**
     * Promoted to searchable tags. Everything else stays as context.
     *
     * @var list<string>
     */
    private const TAGS = ['share_id', 'request_id', 'queue', 'job', 'connection'];

    public function capture(Throwable $e, array $context = []): void
    {
        try {
            withScope(function (Scope $scope) use ($e, $context): void {
                foreach ($context as $key => $value) {
                    if (in_array($key, self::TAGS, true) && $value !== null) {
                        // Tag values must be strings; an int share_id silently
                        // drops the tag otherwise.
                        $scope->setTag($key, (string) $value);
                    }
                }

                $scope->setContext('reelmap', $context);

                captureException($e);
            });
        } catch (Throwable) {
            // See the class docblock — swallow a telemetry failure.
        }
    }
}
