<?php

namespace App\Enums;

/**
 * Where a report sits in triage (T-049, 02 §3.17).
 *
 * `resolved` and `dismissed` are both terminal and both mean "no longer in the
 * queue" — the difference is whether anything happened, and that difference is
 * the only record of whether moderation actually works. Collapsing them into a
 * single `closed` would make the queue look identically healthy whether every
 * report was acted on or every report was waved through.
 */
enum ReportStatus: string
{
    /** Nobody has looked yet. */
    case Open = 'open';

    /** An admin has claimed it. */
    case Reviewing = 'reviewing';

    /** Acted on — content hidden, removed, or the user banned. */
    case Resolved = 'resolved';

    /** Looked at, and there was nothing to do. */
    case Dismissed = 'dismissed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Dismissed], true);
    }

    /** Still needs a human. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }
}
