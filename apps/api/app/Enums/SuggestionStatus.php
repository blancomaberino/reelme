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

    /**
     * Dealt with by hand (T-112).
     *
     * The verb a note-only row needs. `Approved` means "the patch was applied",
     * and "this place closed down" carries no patch — the moderator does
     * whatever the note called for (fixes the listing, hides the place, decides
     * it needs nothing) and records that on the row. Settling one of these as
     * `Approved` would claim an edit that never happened; settling it as
     * `Rejected` would say the report was wrong when it was acted on.
     */
    case Actioned = 'actioned';
}
