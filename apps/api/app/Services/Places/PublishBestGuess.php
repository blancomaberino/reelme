<?php

namespace App\Services\Places;

use App\Console\Commands\PublishAbandonedReviews;
use App\Enums\ShareStatus;
use App\Jobs\Pipeline;
use App\Models\Share;
use Illuminate\Support\Facades\Bus;

/**
 * Confirm-before-publish (T-098): publish an uncertain share's BEST GUESS instead
 * of leaving it as a chore in `review`. Triggered when the sharer skips the
 * confirm step ("Publish as-is") or abandons it (the {@see PublishAbandonedReviews}
 * sweep). Mirrors the confirm path (ShareController::update action:publish) but
 * marks the share `flagged_uncertain` instead of `user_confirmed`, so the resulting
 * place lands in the admin moderation queue (PlacePublisher sets
 * `places.needs_admin_review`) rather than on the sharer's plate.
 *
 * Only reasons whose venue can actually be placed are best-guessable:
 * `low_confidence` resolves normally; `ambiguous_place` attaches to the strongest
 * candidate. `geocode_failed` / `no_place_extracted` / `place_hidden` have no
 * publishable location, so they stay in `review` for an admin (or the sharer's
 * pin) to locate — {@see canPublish()}.
 */
class PublishBestGuess
{
    /** Review reasons whose best guess can be published without human input. */
    public const PLACEABLE_REASONS = ['low_confidence', 'ambiguous_place'];

    /** Whether this share is one the best-guess path can publish (vs. must be located). */
    public function canPublish(Share $share): bool
    {
        return $share->status === ShareStatus::Review
            && in_array($share->review_reason, self::PLACEABLE_REASONS, true);
    }

    /**
     * Publish the share's best guess + flag it for admin review. Returns false
     * (a no-op) when the share isn't best-guessable or the optimistic transition
     * guard was lost to a concurrent publish/confirm.
     */
    public function publish(Share $share): bool
    {
        if (! $this->canPublish($share)) {
            return false;
        }

        // Ambiguous match → the best guess is the strongest candidate; stash it as
        // the picked place so the re-dispatched ResolvePlace attaches straight to
        // it (bypassing the dedup that couldn't decide). No candidate to pick → not
        // best-guessable, leave it for an admin.
        if ($share->review_reason === 'ambiguous_place') {
            $picked = $this->strongestCandidate($share);
            if ($picked === null) {
                return false;
            }
            $meta = is_array($share->review_meta_json) ? $share->review_meta_json : [];
            $meta['picked_place_id'] = $picked;
            $share->review_meta_json = $meta;
        }

        $share->flagged_uncertain = true;
        $share->save();

        // Only dispatch if we won the optimistic guard — a concurrent confirm/skip
        // may already have advanced the row; dispatching regardless would enqueue a
        // duplicate resolve→publish chain.
        if (! $share->transitionTo(ShareStatus::Analyzing)) {
            return false;
        }

        Bus::chain(Pipeline::chain($share->id, 'resolve'))->dispatch();

        return true;
    }

    /** The offered candidate with the highest similarity, or null when none carry an id. */
    private function strongestCandidate(Share $share): ?int
    {
        $meta = is_array($share->review_meta_json) ? $share->review_meta_json : [];
        // Single-place review shape (ResolvePlace writes both keys); prefer the
        // explicit `candidates`, fall back to the first pending venue's candidates.
        $candidates = $meta['candidates'] ?? ($meta['pending'][0]['candidates'] ?? []);
        if (! is_array($candidates)) {
            return null;
        }

        $best = null;
        $bestScore = -1.0;
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! isset($candidate['place_id'])) {
                continue;
            }
            $score = isset($candidate['similarity']) ? (float) $candidate['similarity'] : 0.0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int) $candidate['place_id'];
            }
        }

        return $best;
    }
}
