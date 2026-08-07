<?php

namespace App\Support\Profiles;

use App\Models\User;
use App\Services\Moderation\BlockUsers;

/**
 * May this viewer see this profile at all? (T-054.)
 *
 * Extracted because the rule had TWO implementations — `ProfileController::
 * assertViewable()` and `ProfilePlacesRequest::authorize()` — and T-054 added
 * blocking to the first one only. `GET /users/{username}/places` stayed readable
 * by an account the owner had blocked, and nothing failed: each copy was correct
 * on its own terms, and the second lived in a different file from the one being
 * edited.
 *
 * Every profile read path goes through here now. A new one that forgets is a
 * new one with no gate at all — a visible mistake rather than a silent
 * divergence.
 */
class ProfileVisibility
{
    /** Aborts 404 when the profile is not viewable. Never 403 — see below. */
    public static function assert(?User $viewer, User $subject): void
    {
        // A block hides the profile in BOTH directions. 404, and the SAME 404 a
        // private profile gives: "you are blocked" is itself information, and
        // naming the account that blocked you is exactly the nudge that starts
        // a second account.
        abort_if(app(BlockUsers::class)->betweenExists($viewer?->id, $subject->id), 404);

        if ($subject->is_public) {
            return;
        }

        abort_unless($viewer?->id === $subject->id, 404);
    }
}
