/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/redemption.json
 */
/**
 * A single-use offer code (T-043, 03 §2.13/§3.4). The BEARER CREDENTIALS — `code`, `code_display`, `qr_payload` — are present only on the holder's own reads (POST /redemptions, GET /redemptions/{id} as the diner, GET /me/redemptions). The venue operator reads the same row WITHOUT them: a code they have not been presented with is a free meal, so their log must never double as a list of live codes.
 */
export interface Redemption {
  id: string;
  offer_id: string;
  status: 'issued' | 'redeemed' | 'expired' | 'void';
  /**
   * Computed, not read off `status`: the expiry sweep runs on a schedule, so a code past its window still reads `issued` until it catches up. A client must not offer a dead code to a till.
   */
  is_live: boolean;
  issued_at: string;
  expires_at: string | null;
  redeemed_at: string | null;
  /**
   * Ten Crockford base32 characters, bare. Holder-only. Input is folded (case, grouping, O→0, I/L→1) before lookup, so it can be read aloud.
   */
  code?: string;
  /**
   * The grouped form (7F3K-92QX-AB) so a client never re-implements the formatting. Holder-only.
   */
  code_display?: string;
  /**
   * `v1.<code>.<hmac>` — signed over the code AND the row id, so a payload lifted from one redemption cannot be replayed against another. Holder-only.
   */
  qr_payload?: string;
  /**
   * Who earns from this visit, FROZEN at issue (02 §5). Never recomputed: the share can be edited or deleted afterwards, and a payout that moves retroactively cannot be reconciled.
   */
  attribution: {
    influencer_id: string | null;
    /**
     * Null once the underlying share is deleted (the FK is SET NULL). The influencer survives, and T-044's ledger rows are the immutable copy.
     */
    share_id: string | null;
  };
  offer?: Offer;
}
/**
 * Present when the offer was eager-loaded — the diner's wallet needs the terms alongside the code.
 */
export interface Offer {
  id: string;
  place_id: string;
  title: string;
  description: string | null;
  discount_type: 'percent' | 'fixed_amount' | 'free_item';
  discount_value: number;
  /**
   * Free-text terms shown to the diner before a redemption is issued (06 §2.2).
   */
  terms: string | null;
  starts_at: string;
  /**
   * Null = open-ended. The API defaults an omitted end date to starts_at + 90 days (06 §2.2 caps a run at 90 days), so null reaches clients only for rows created outside the public API.
   */
  ends_at: string | null;
  /**
   * Lifetime cap on redemptions; null = unlimited.
   */
  quota_total: number | null;
  quota_per_user: number;
  /**
   * Per-day cap (06 §2.2 anti-fraud throttle); null = unlimited.
   */
  quota_per_day: number | null;
  redemptions_count: number;
  /**
   * Redemptions left under quota_total; null when unlimited — a distinction a raw subtraction cannot express.
   */
  remaining_quota: number | null;
  status: 'draft' | 'active' | 'paused' | 'expired' | 'archived';
  /**
   * Computed, not derived from `status`: an offer whose window lapsed overnight still reads status=active. Covers the window and the lifetime quota; the per-day quota is settled at issue time.
   */
  is_redeemable: boolean;
  /**
   * Compact venue block, present only on the flat /offers reads — never on the ?include=offers embed, where the caller already holds the place.
   */
  place?: {
    id: string;
    name: string;
    slug: string;
    city: string | null;
    country_code: string | null;
    thumbnail_url: string | null;
  };
  created_at: string | null;
  updated_at: string | null;
}
