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
     * entry status. `review` can also re-enter `fetching` on manual resubmission.
     *
     * @return array<int, ShareStatus>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Fetching, self::Failed],
            self::Fetching => [self::Analyzing, self::Review, self::Failed],
            self::Analyzing => [self::Review, self::Published, self::Failed],
            self::Review => [self::Published, self::Rejected, self::Fetching, self::Failed],
            self::Failed => [self::Fetching, self::Analyzing],
            self::Published, self::Rejected => [],
        };
    }

    /** Fully terminal — no outgoing transitions (published/rejected). */
    public function isTerminal(): bool
    {
        return $this->transitions() === [];
    }
}
