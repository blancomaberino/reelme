import { useInfiniteQuery } from '@tanstack/react-query';

import { nearParam, type Region } from '@/lib/geo';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Paginated, PlaceSummary } from '../places';

/**
 * The zone choices, in metres (T-158).
 *
 * Three steps, not a slider: the question is "how far will I go", and a person
 * answers that in categories — around the corner, worth a walk, worth a ride —
 * not in 250-metre increments. A slider would also refetch on every frame of a
 * drag, which is a lot of requests to answer a question with three answers.
 */
export const ZONES_M = [1000, 2000, 5000] as const;

export type ZoneM = (typeof ZONES_M)[number];

export const DEFAULT_ZONE_M: ZoneM = 2000;

export type TonightQuery = {
  at: Pick<Region, 'latitude' | 'longitude'> | null;
  radiusM: ZoneM;
  dish: string;
  openNow: boolean;
};

async function fetchPage(q: TonightQuery, cursor: string | null): Promise<Paginated<PlaceSummary>> {
  const params: Record<string, string | number> = {
    near: nearParam(q.at),
    radius_m: q.radiusM,
    // Distance, not recency: the screen's question is "here, now", and a place
    // that was shared this morning is not a better answer than one 200m away.
    sort: 'distance',
    limit: 20,
  };

  // Without this the keyset cursor is never sent and every "next page" re-asks
  // for the FIRST one — an infinite list of the same twenty places, which looks
  // like a working list until you scroll.
  if (cursor) params.cursor = cursor;

  const dish = q.dish.trim();
  if (dish.length > 0) params.dish = dish;
  // Sent only when ON. `open_now=0` and an absent parameter mean the same thing
  // to the API, but sending the flag either way would put it in the query key of
  // requests that do not filter, which makes the cache harder to read.
  if (q.openNow) params.open_now = 1;

  const { data } = await api.get<Paginated<PlaceSummary>>('/places', { params });
  return data;
}

/**
 * "Where do I eat, here, now" (T-158) — zone × dish × open-now, around the
 * viewer, nearest first.
 *
 * Every input is in the query key, which is what makes the screen re-ask when
 * any of them changes rather than re-rendering the same page. The API floor on
 * `?dish=` is three characters, so a shorter one is treated as "no dish filter"
 * HERE rather than sent and 422'd — a half-typed word should narrow nothing, not
 * empty the screen.
 *
 * Disabled without a fix. "Near you" with no position is either an empty screen
 * or a list from somewhere the diner is not, and the second is worse.
 */
export const MIN_DISH_QUERY = 3;

export function useTonight(q: TonightQuery) {
  const dish = q.dish.trim().length >= MIN_DISH_QUERY ? q.dish.trim() : '';
  const effective: TonightQuery = { ...q, dish };
  const near = nearParam(q.at);

  return useInfiniteQuery({
    queryKey: queryKeys.tonight(near, q.radiusM, dish, q.openNow),
    queryFn: ({ pageParam }) => fetchPage(effective, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.next_cursor,
    enabled: near !== '',
    staleTime: 60_000,
  });
}
