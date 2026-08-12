<?php

namespace App\Enums;

/**
 * Where a suggested place edit sits (T-083).
 *
 * Deliberately NOT {@see ClaimStatus}, whose middle state is `verified`: a claim
 * is proven, a suggested edit is *accepted* by a human who could equally have
 * accepted a different value. Sharing the enum would have put the word "verified"
 * on a moderator's judgement call and, more practically, made every
 * `status = 'pending'` query ambiguous about which table it meant.
 */
enum SuggestionStatus: string
{
    /** Waiting on a moderator. The only state the queue shows by default. */
    case Pending = 'pending';

    /** Applied to the place — see the linked `place_edits` row for what landed. */
    case Approved = 'approved';

    /** Declined, with a reason. */
    case Rejected = 'rejected';

    /** Settled either way — no longer anybody's work. */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
