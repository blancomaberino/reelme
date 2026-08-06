/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/influencer-dashboard.json
 */
/**
 * The influencer earnings funnel (T-048, 06 §5.2) — GET /me/influencer/dashboard. Answers 'which of my posts actually made money' in one payload, deliberately: the visits and the euros have to be read in the same glance, and two requests could disagree on screen while both were individually correct. Every count is derived from the FROZEN attribution on `redemptions` (`attributed_influencer_id`/`attributed_share_id`), never re-walked through shares, so a deleted or re-analysed share cannot change what a past period earned. Requires a CLAIMED influencer identity; 403 otherwise.
 */
export interface InfluencerDashboard {
  /**
   * The window these figures cover. Echoed back so a cached client render can't mislabel itself.
   */
  period: '30d' | '90d' | 'all';
  influencer: {
    id: string;
    handle: string;
    platform: string;
  };
  /**
   * One COHORT, scoped by the redemption's issue date — `issued`, `redeemed` and `earnings` all describe the same set of codes, so a conversion rate computed from them is meaningful. Note there is no `offer_taps` stage: the app has no tap event, and emitting the issued count twice under two names would read as a step that never loses anyone.
   */
  funnel: {
    /**
     * How many of this identity's posts currently back a place on the map. Current reach, NOT a period figure.
     */
    shares: number;
    /**
     * Attributed place-page views. `null` means NOT TRACKED — distinct from 0, which would claim nobody looked. No view tracking exists yet.
     */
    views: number | null;
    /**
     * The date from which `views` is real. `null` while untracked, so a chart is never read as historical truth.
     */
    views_tracked_since: string | null;
    /**
     * Codes handed out. Includes expired and voided ones — a code really was issued.
     */
    issued: number;
    /**
     * Codes honoured at the till. The only billable state (06 §2.3); expired and void are never counted here.
     */
    redeemed: number;
    earnings: Money;
  };
  /**
   * Every place that earned, best first. The per-place amounts sum to `funnel.earnings`.
   */
  by_place: PlaceRow[];
  /**
   * The first five of `by_place`, for the headline block.
   */
  top_places: PlaceRow[];
  /**
   * The same breakdown per originating post, keyed by the frozen `attributed_share_id`. A row whose share was deleted survives with `post: null` rather than vanishing — losing it would quietly shrink a historical total.
   */
  by_source: {
    share_id: string | null;
    post: {
      url: string;
      platform: string;
    } | null;
    issued: number;
    redeemed: number;
    earnings: Money;
  }[];
  /**
   * Read LIVE from the ledger, never from the cached funnel — a stale balance is a cash-out button that lies.
   */
  money: {
    /**
     * Cashable right now. Signed, so a post-payout void (06 §4.4) can leave this negative — carried against future earnings, since v1 has no clawback transfers.
     */
    available: {
      /**
       * MINOR units (cents). Never a float — these are multiplied by basis points and summed across a month.
       */
      amount: number;
      currency: string;
    };
    /**
     * What the balance must reach before a payout can be requested (06 §4.3).
     */
    threshold: {
      /**
       * MINOR units (cents). Never a float — these are multiplied by basis points and summed across a month.
       */
      amount: number;
      currency: string;
    };
  };
  /**
   * Stripe Connect state, read live from Stripe rather than a cached flag — Stripe re-verifies and requirements reappear.
   */
  connect: {
    onboarded: boolean;
    payouts_enabled: boolean;
  };
}
export interface Money {
  /**
   * MINOR units (cents). Never a float — these are multiplied by basis points and summed across a month.
   */
  amount: number;
  currency: string;
}
export interface PlaceRow {
  place: {
    id: string;
    slug: string;
    name: string;
  };
  issued: number;
  redeemed: number;
  earnings: Money;
}
