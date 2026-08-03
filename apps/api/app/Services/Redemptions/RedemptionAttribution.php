<?php

namespace App\Services\Redemptions;

use App\Models\Offer;
use App\Models\Share;
use Illuminate\Support\Facades\Log;

/**
 * Who earns from this redemption (T-043, 02 §5, 06 §3).
 *
 * Resolved ONCE, at issue, and then denormalised onto the row. Never recomputed
 * — that is the whole design. The share that sent the diner here can be edited,
 * re-analysed by a newer model, or deleted outright before they ever walk in;
 * the person who earns from the visit was settled when the code was handed out,
 * and a payout that could change retroactively is a payout nobody can reconcile.
 *
 * **Last-touch**, per 06 §5: the influencer credited is the one on the share the
 * diner actually navigated from. When the client sends no context — a diner who
 * found the place by searching the map — it falls back to the place's primary
 * source, which is the venue's most prominent attribution and the honest answer
 * to "who put this place on the map for us".
 */
class RedemptionAttribution
{
    /**
     * @return array{influencer_id: int|null, share_id: int|null}
     */
    public function resolve(Offer $offer, ?int $claimedShareId): array
    {
        $share = $this->validatedShare($offer, $claimedShareId);

        if ($share !== null) {
            return [
                'influencer_id' => $this->influencerFromShare($offer, $share),
                'share_id' => $share->id,
            ];
        }

        return $this->fromPrimarySource($offer);
    }

    /**
     * The client-claimed share, but only if it genuinely touches this offer's
     * place.
     *
     * The share id arrives from the CLIENT, which makes it an attribution a
     * diner could otherwise point at any influencer they like — including
     * themselves. Verifying it against `place_sources` is what stops the
     * referral context from being a self-serve payout instruction. A claim that
     * fails the check is dropped to the fallback rather than rejected: the diner
     * did nothing wrong from their side, and refusing the code over it would
     * turn an attribution detail into a broken redemption.
     */
    private function validatedShare(Offer $offer, ?int $claimedShareId): ?Share
    {
        if ($claimedShareId === null) {
            return null;
        }

        $share = Share::query()
            ->whereKey($claimedShareId)
            ->whereExists(fn ($q) => $q->from('place_sources')
                ->whereColumn('place_sources.share_id', 'shares.id')
                ->where('place_sources.place_id', $offer->place_id))
            ->first();

        if ($share === null) {
            Log::info('redemption.attribution_share_rejected', [
                'offer_id' => $offer->id,
                'claimed_share_id' => $claimedShareId,
                'place_id' => $offer->place_id,
            ]);
        }

        return $share;
    }

    /** The influencer behind that share's post at this place, if any. */
    private function influencerFromShare(Offer $offer, Share $share): ?int
    {
        $influencerId = $share->placeSources()
            ->where('place_id', $offer->place_id)
            ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
            ->value('source_posts.influencer_id');

        return $influencerId === null ? null : (int) $influencerId;
    }

    /**
     * No usable referral context: credit the place's primary source.
     *
     * @return array{influencer_id: int|null, share_id: int|null}
     */
    private function fromPrimarySource(Offer $offer): array
    {
        $primary = $offer->place?->primarySource()->with('sourcePost')->first();

        return [
            'influencer_id' => $primary?->sourcePost?->influencer_id,
            'share_id' => $primary?->share_id,
        ];
    }
}
