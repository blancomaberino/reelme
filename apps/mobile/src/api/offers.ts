// Restaurant offer types (T-042) — GET/POST/PATCH/DELETE /offers, GET /me/venues.
//
// The response shape is re-exported from @reelmap/contracts (T-102):
// offer.json is the single source of truth shared with OfferResource, so a
// renamed or removed API field breaks `tsc`, not the device.
import type { Offer as ContractOffer, PlaceSummary } from '@reelmap/contracts';

/** An offer as the API returns it. */
export type Offer = ContractOffer;

export type OfferStatus = Offer['status'];

export type DiscountType = Offer['discount_type'];

/**
 * A venue the signed-in user OPERATES (GET /me/venues) — a place they hold a
 * verified claim on. Distinct from the places in their personal collection.
 */
export type Venue = PlaceSummary;

/**
 * The body of a create/update.
 *
 * `place_id` is create-only: an offer cannot be moved between venues, so the
 * update type omits it rather than sending a field the API would ignore.
 *
 * `discount_value` is an integer in the unit its `discount_type` selects — a
 * percentage, MINOR currency units (350 = €3.50), or a count of free items.
 * Never a float: money in a float is a rounding bug waiting to happen.
 */
export type OfferDraft = {
  title: string;
  description?: string | null;
  discount_type: DiscountType;
  discount_value: number;
  terms?: string | null;
  starts_at: string;
  ends_at?: string | null;
  quota_total?: number | null;
  quota_per_user?: number;
  quota_per_day?: number | null;
  status?: Extract<OfferStatus, 'draft' | 'active' | 'paused'>;
};

export type CreateOfferInput = OfferDraft & { place_id: string };

export type UpdateOfferInput = Partial<OfferDraft> & { id: string };

/**
 * 06 §2.2 caps a single offer's run at 90 days (renewable), and a percentage
 * discount at 5–50%. Mirrored here so the form can refuse before a round trip;
 * the API enforces them regardless — this is a courtesy, not the boundary.
 */
export const OFFER_LIMITS = {
  maxWindowDays: 90,
  percentMin: 5,
  percentMax: 50,
  maxFreeItems: 20,
} as const;

/** The run lengths the form offers, in days. All within `maxWindowDays`. */
export const OFFER_DURATIONS = [7, 14, 30, 60, 90] as const;

/**
 * What the operator actually sees on the card — six states, not the five in
 * `status`.
 *
 * `status` alone cannot say this. It reads `active` from the moment an offer is
 * published and NOTHING rewrites it when the window opens or closes, so a
 * single column has to cover "starts next Monday", "running", and "ended last
 * night". Splitting them here, from the window, is why the badge can never tell
 * an operator a lapsed promotion is still live.
 */
export type OfferState = 'live' | 'scheduled' | 'ended' | 'soldOut' | 'draft' | 'paused' | 'archived';

export function offerState(offer: Offer, now: Date = new Date()): OfferState {
  if (offer.status === 'archived') return 'archived';
  if (offer.status === 'draft') return 'draft';
  if (offer.status === 'paused') return 'paused';
  if (offer.status === 'expired') return 'ended';

  const at = now.getTime();
  if (new Date(offer.starts_at).getTime() > at) return 'scheduled';
  if (offer.ends_at && new Date(offer.ends_at).getTime() < at) return 'ended';
  // In-window but the lifetime cap is used up: still worth distinguishing from
  // `live`, because the fix is raising the quota, not extending the dates.
  if (offer.remaining_quota === 0) return 'soldOut';

  return 'live';
}

/** Can this state be paused (i.e. is it currently reachable by diners)? */
export function isPausable(state: OfferState): boolean {
  return state === 'live' || state === 'scheduled' || state === 'soldOut';
}
