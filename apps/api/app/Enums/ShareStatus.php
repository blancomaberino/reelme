<?php

namespace App\Enums;

enum ShareStatus: string
{
    case Pending = 'pending';
    case Fetching = 'fetching';
    case Analyzing = 'analyzing';
    case Review = 'review';
    case Published = 'published';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * Allowed next states (02-data-model §3.5). `published`/`rejected` are
     * terminal; `failed` is terminal-but-retryable, so it re-enters at a stage
     * entry status. `review` can re-enter `fetching` on manual resubmission or
     * `analyzing` when a user confirms corrections (re-runs resolve→publish).
     * Every non-terminal state may transition to `rejected` — that is the
     * user-discard path (DELETE /shares/{id}), valid from anywhere in flight.
     *
     * @return array<int, ShareStatus>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Fetching, self::Failed, self::Rejected],
            self::Fetching => [self::Analyzing, self::Review, self::Failed, self::Rejected],
            self::Analyzing => [self::Review, self::Published, self::Failed, self::Rejected],
            self::Review => [self::Published, self::Rejected, self::Fetching, self::Analyzing, self::Failed],
            self::Failed => [self::Fetching, self::Analyzing, self::Rejected],
            self::Published, self::Rejected => [],
        };
    }

    /** Fully terminal — no outgoing transitions (published/rejected). */
    public function isTerminal(): bool
    {
        return $this->transitions() === [];
    }
}
