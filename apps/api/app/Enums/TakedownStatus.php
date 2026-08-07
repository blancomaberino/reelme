<?php

namespace App\Enums;

/**
 * A rightsholder's takedown request (T-049, IR-2 / R-07).
 *
 * `counter_notice` is a distinct state rather than a note on `closed` because
 * the DMCA gives the uploader a right of reply, and a system that cannot
 * represent "they disputed it" cannot show that the reply was considered — the
 * exact thing a safe-harbour argument rests on.
 */
enum TakedownStatus: string
{
    /** Logged, not yet acted on. */
    case Received = 'received';

    /** Content unpublished and media deleted. */
    case Actioned = 'actioned';

    /** The uploader disputed it; awaiting a decision. */
    case CounterNotice = 'counter_notice';

    /** Finished, whichever way it went. */
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return in_array($this, [self::Received, self::CounterNotice], true);
    }
}
