<?php

namespace App\Services\Influencers;

use App\Enums\DashboardPeriod;
use App\Enums\LedgerAccount;
use App\Enums\RedemptionStatus;
use App\Models\Influencer;
use App\Models\LedgerEntry;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Redemptions\RedemptionAttribution;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind an influencer's dashboard (T-048, 06 §5.2).
 *
 * The job is to make earnings legible — "this reel drove 12 paid visits" — so
 * every figure here has to survive the question "where did that come from".
 * Three rules follow from that, and they are the whole design:
 *
 * 1. **Counts come from `redemptions.attributed_*`, never from a
 *    share → source_post walk.** Attribution is frozen at issue by
 *    {@see RedemptionAttribution} precisely because
 *    the share can be edited, re-analysed or deleted before the diner walks in.
 *    Re-deriving it here would make last month's dashboard change when a share
 *    is deleted today — and it would disagree with what was actually paid.
 *
 * 2. **Money comes from the ledger**, and from the same helpers the wallet uses,
 *    so the dashboard and the books cannot drift.
 *
 * 3. **The funnel is ONE cohort.** Every stage is scoped by the redemption's
 *    `created_at`, so `issued`, `redeemed` and `earnings` all describe the same
 *    set of codes. Scoping earnings by the ledger entry's own date instead would
 *    put a code issued on day 29 and honoured on day 31 into different buckets,
 *    and a "conversion rate" computed across two different cohorts is a number
 *    that means nothing.
 */
class DashboardMetrics
{
    /** Top-places list length (06 §5.2). */
    private const TOP_PLACES = 5;

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Influencer $influencer, DashboardPeriod $period): array
    {
        $currency = (string) config('monetization.currency');

        return [
            'period' => $period->key(),
            'funnel' => $this->funnel($influencer, $period, $currency),
            'by_place' => $this->byPlace($influencer, $period, $currency),
            'by_source' => $this->bySource($influencer, $period, $currency),
            'top_places' => $this->byPlace($influencer, $period, $currency, self::TOP_PLACES),
        ];
    }

    /**
     * Shares → views → issued → redeemed → earnings.
     *
     * **`offer_taps` is deliberately absent** even though 06 §5.2 lists it: the
     * app has no tap event, so the only number available for it is the count of
     * issued codes — which is the next stage. Emitting the same figure twice
     * under two names would read as a conversion step that never loses anyone,
     * which is worse than admitting the stage isn't measured.
     *
     * @return array<string, mixed>
     */
    private function funnel(Influencer $influencer, DashboardPeriod $period, string $currency): array
    {
        $counts = $this->redemptionCounts($influencer, $period);

        return [
            // Current reach, not a period figure: how many of this identity's
            // posts are on the map right now. This one DOES walk source_posts,
            // and legitimately — it is a question about content that exists,
            // not about money that moved.
            'shares' => $this->liveShareCount($influencer),
            'views' => null,
            // Stated so a client cannot read `views: null` as "zero views".
            // There is no view tracking yet (T-048 gotcha); when it lands this
            // carries the date from which the number is real, so charts are
            // never read as historical truth.
            'views_tracked_since' => null,
            'issued' => $counts['issued'],
            'redeemed' => $counts['redeemed'],
            'earnings' => $this->money($this->earnings($influencer, $period, $currency), $currency),
        ];
    }

    /**
     * Issued and redeemed, in one pass.
     *
     * `expired` and `void` count as ISSUED (a code really was handed out) but
     * never as redeemed — only `redeemed` is billable per 06 §2.3, and a funnel
     * that counted expired codes as conversions would inflate every rate on the
     * screen.
     *
     * @return array{issued: int, redeemed: int}
     */
    private function redemptionCounts(Influencer $influencer, DashboardPeriod $period): array
    {
        $row = $this->scopedRedemptions($influencer, $period)
            ->selectRaw('count(*) AS issued')
            ->selectRaw('count(*) FILTER (WHERE status = ?) AS redeemed', [RedemptionStatus::Redeemed->value])
            ->first();

        return [
            'issued' => (int) ($row->issued ?? 0),
            'redeemed' => (int) ($row->redeemed ?? 0),
        ];
    }

    /**
     * What this identity earned on the cohort's redemptions.
     *
     * CREDITS minus debits on `influencer_earnings`, restricted to entries
     * REFERENCING those redemptions. Two things fall out of that scoping, both
     * wanted:
     *
     * - A void's reversal (06 §4.4) nets off, so a disputed visit stops showing
     *   as earnings.
     * - The escrow→user transfer posted on claim ({@see
     *   \App\Listeners\ReleaseInfluencerEscrow}) is NOT counted, because it
     *   references the influencer, not a redemption. Otherwise claiming an
     *   identity would book every historical euro a second time, as if it had
     *   all been earned on claim day — the task's double-count gotcha.
     *
     * Escrow rows (`user_id` null) are included on purpose: before a claim the
     * money is still this identity's, and a dashboard that showed nothing would
     * be telling an influencer their reels earned zero.
     */
    private function earnings(Influencer $influencer, DashboardPeriod $period, string $currency): int
    {
        $normal = LedgerAccount::InfluencerEarnings->normalDirection()->value;

        $sum = LedgerEntry::query()
            ->where('account', LedgerAccount::InfluencerEarnings)
            ->where('currency', $currency)
            ->where('reference_type', 'redemption')
            ->whereIn('reference_id', $this->scopedRedemptions($influencer, $period)->select('id'))
            ->toBase()
            ->selectRaw(
                'coalesce(sum(CASE WHEN direction = ? THEN amount ELSE -amount END), 0) AS total',
                [$normal],
            )
            ->value('total');

        return (int) $sum;
    }

    /**
     * Earnings and conversion per place, best-earning first.
     *
     * Grouped through the OFFER's place rather than the redemption's, because
     * the offer is what the fee was charged against.
     *
     * @return list<array<string, mixed>>
     */
    private function byPlace(Influencer $influencer, DashboardPeriod $period, string $currency, ?int $limit = null): array
    {
        $normal = LedgerAccount::InfluencerEarnings->normalDirection()->value;

        $rows = $this->scopedRedemptions($influencer, $period)
            ->join('offers', 'offers.id', '=', 'redemptions.offer_id')
            ->join('places', 'places.id', '=', 'offers.place_id')
            ->leftJoin('ledger_entries', function ($join) use ($currency): void {
                $join->on('ledger_entries.reference_id', '=', 'redemptions.id')
                    ->where('ledger_entries.reference_type', '=', 'redemption')
                    ->where('ledger_entries.account', '=', LedgerAccount::InfluencerEarnings->value)
                    ->where('ledger_entries.currency', '=', $currency);
            })
            ->groupBy('places.id', 'places.slug', 'places.name')
            ->select('places.id', 'places.slug', 'places.name')
            ->selectRaw('count(DISTINCT redemptions.id) AS issued')
            ->selectRaw(
                'count(DISTINCT redemptions.id) FILTER (WHERE redemptions.status = ?) AS redeemed',
                [RedemptionStatus::Redeemed->value],
            )
            ->selectRaw(
                'coalesce(sum(CASE WHEN ledger_entries.direction = ? THEN ledger_entries.amount '.
                'WHEN ledger_entries.direction IS NULL THEN 0 ELSE -ledger_entries.amount END), 0) AS earned',
                [$normal],
            )
            ->orderByRaw('earned DESC, count(DISTINCT redemptions.id) DESC, places.id ASC')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        return $rows->map(fn ($row) => [
            'place' => ['id' => (string) $row->id, 'slug' => $row->slug, 'name' => $row->name],
            'issued' => (int) $row->issued,
            'redeemed' => (int) $row->redeemed,
            'earnings' => $this->money((int) $row->earned, $currency),
        ])->all();
    }

    /**
     * The same breakdown per originating post.
     *
     * Keyed by the FROZEN `attributed_share_id`, so a row survives its share
     * being deleted — it simply reports `post: null` rather than vanishing.
     * Losing the row would quietly reduce a historical total and make the
     * per-post numbers stop summing to the funnel.
     *
     * @return list<array<string, mixed>>
     */
    private function bySource(Influencer $influencer, DashboardPeriod $period, string $currency): array
    {
        $normal = LedgerAccount::InfluencerEarnings->normalDirection()->value;

        $rows = $this->scopedRedemptions($influencer, $period)
            ->leftJoin('shares', 'shares.id', '=', 'redemptions.attributed_share_id')
            ->leftJoin('source_posts', 'source_posts.id', '=', 'shares.source_post_id')
            ->leftJoin('ledger_entries', function ($join) use ($currency): void {
                $join->on('ledger_entries.reference_id', '=', 'redemptions.id')
                    ->where('ledger_entries.reference_type', '=', 'redemption')
                    ->where('ledger_entries.account', '=', LedgerAccount::InfluencerEarnings->value)
                    ->where('ledger_entries.currency', '=', $currency);
            })
            ->groupBy('redemptions.attributed_share_id', 'source_posts.id', 'source_posts.url', 'source_posts.platform')
            ->select('redemptions.attributed_share_id AS share_id', 'source_posts.url', 'source_posts.platform')
            ->selectRaw('count(DISTINCT redemptions.id) AS issued')
            ->selectRaw(
                'count(DISTINCT redemptions.id) FILTER (WHERE redemptions.status = ?) AS redeemed',
                [RedemptionStatus::Redeemed->value],
            )
            ->selectRaw(
                'coalesce(sum(CASE WHEN ledger_entries.direction = ? THEN ledger_entries.amount '.
                'WHEN ledger_entries.direction IS NULL THEN 0 ELSE -ledger_entries.amount END), 0) AS earned',
                [$normal],
            )
            ->orderByRaw('earned DESC, count(DISTINCT redemptions.id) DESC')
            ->get();

        return $rows->map(fn ($row) => [
            'share_id' => $row->share_id === null ? null : (string) $row->share_id,
            'post' => $row->url === null ? null : ['url' => $row->url, 'platform' => $row->platform],
            'issued' => (int) $row->issued,
            'redeemed' => (int) $row->redeemed,
            'earnings' => $this->money((int) $row->earned, $currency),
        ])->all();
    }

    /**
     * Redemptions attributed to this identity within the window.
     *
     * Returns a fresh QUERY BUILDER each call rather than a shared instance —
     * these get used as a subquery and as the base of two different joins, and
     * a reused builder would accumulate the previous caller's clauses.
     */
    private function scopedRedemptions(Influencer $influencer, DashboardPeriod $period): QueryBuilder
    {
        $since = $period->since();

        return DB::table('redemptions')
            ->where('redemptions.attributed_influencer_id', $influencer->id)
            ->when($since !== null, fn ($q) => $q->where('redemptions.created_at', '>=', $since));
    }

    /**
     * How many of this identity's posts currently back a place on the map.
     *
     * The one figure that legitimately walks `source_posts → place_sources`:
     * it is a statement about content that exists now, not about money that
     * moved, so re-deriving it is correct rather than dangerous.
     */
    private function liveShareCount(Influencer $influencer): int
    {
        return (int) DB::table('place_sources')
            ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
            ->where('source_posts.influencer_id', $influencer->id)
            ->distinct()
            ->count('place_sources.share_id');
    }

    /** @return array{amount: int, currency: string} */
    private function money(int $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency];
    }
}
