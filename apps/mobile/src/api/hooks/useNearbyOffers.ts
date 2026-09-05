import { useQuery } from '@tanstack/react-query';

import { nearParam, type Region } from '@/lib/geo';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Offer } from '../offers';

/**
 * How far out the LIST looks. 2km is walking distance in a city centre — far
 * enough to be worth a list, near enough that "10% off" is still an offer
 * someone would actually cross town for.
 *
 * The MAP does not use this: it asks for whatever is on screen, so panning
 * somewhere is how you see what is there.
 */
export const BROWSE_RADIUS_M = 2000;

/** The API's ceiling on `radius_m`. A wider viewport is clamped, not rejected. */
export const MAX_RADIUS_M = 50_000;

/**
 * Nearby redeemable offers (T-047, 05 screen #17).
 *
 * `active=1` is not optional here. Without it the browse lists offers whose
 * window closed overnight — `status` still reads `active`, because nothing
 * rewrites the column when a date passes — and a diner walks to a restaurant
 * for a promotion that ended.
 *
 * The position is quantized by {@link nearParam} — shared with every other
 * `?near=` caller, not re-spelled here. It stops a hand-held phone's GPS jitter
 * refetching the whole list, and it is also the coarseness of what LEAVES the
 * device: a second inline `toFixed(4)` would mean tightening that privacy
 * property in one place and shipping precise coordinates from the other, with
 * green tests on both.
 */
export function useNearbyOffers(at: Pick<Region, 'latitude' | 'longitude'> | null, radiusM = BROWSE_RADIUS_M) {
  const near = nearParam(at);
  const radius = Math.min(Math.max(Math.round(radiusM), 1), MAX_RADIUS_M);

  return useQuery({
    queryKey: queryKeys.nearbyOffers(near, radius),
    queryFn: async (): Promise<Offer[]> => {
      const { data } = await api.get<{ data: Offer[] }>('/offers', {
        params: { near, radius_m: radius, active: 1, limit: 50 },
      });
      return data.data;
    },
    enabled: near !== '',
    staleTime: 60_000,
  });
}
