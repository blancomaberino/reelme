<?php

namespace App\Jobs\Concerns;

use App\Enums\ShareStatus;
use App\Models\Share;

/**
 * Shared `failed()` hook for pipeline jobs: mark the share failed with a
 * taxonomy code, but only if the transition is still legal (never crash inside
 * failed() — e.g. the share is already terminal).
 */
trait FailsShareOnError
{
    public function failed(\Throwable $e): void
    {
        $share = Share::find($this->shareId);

        if ($share !== null && $share->canTransitionTo(ShareStatus::Failed)) {
            $share->transitionTo(ShareStatus::Failed, $this->failureCode());
        }
    }

    protected function failureCode(): string
    {
        return 'unknown';
    }
}
