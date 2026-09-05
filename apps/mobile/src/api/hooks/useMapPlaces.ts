import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';

import { bboxParam, mapQueryFor, nearParam, type Region } from '@/lib/geo';
import type { ViewerPoint } from '@/lib/use-viewer-position';

import { api } from '../client';
import { type MapFilters, queryKeys } from '../keys';
import type { MapResponse } from '../places';

export type MapData = {
  pins: MapResponse['data']['pins'];
  clusters: MapResponse['data']['clusters'];
  truncated: boolean;
  /**
   * When these rows were fetched. Stamped INTO the payload rather than read from
   * react-query's `dataUpdatedAt`, because the age has to describe the rows on
   * screen and `dataUpdatedAt` describes the KEY: it is 0 for a key that has
   * never resolved, which is exactly the window `keepPreviousData` is showing
   * the previous viewport's pins in. Handing that 0 to the pin sheet reads as
   * "the epoch", so the open/closed cue vanished for the length of every pan —
   * and, offline or on a hanging refetch, did not come back.
   *
   * Travelling with the data also survives persistence: a payload rehydrated
   * from disk carries the moment it was really fetched, which is the question
   * the staleness gate is asking.
   */
  fetchedAt: number;
};

async function fetchMapPlaces(region: Region, filters: MapFilters, near: string | null): Promise<MapData> {
  const { bbox, zoom } = mapQueryFor(region);
  const params: Record<string, string | number | string[]> = { bbox: bboxParam(bbox), zoom };
  // Omitted rather than sent empty: the API 422s a malformed `near` instead of
  // ignoring it, which is what stops a caller believing it asked for distances
  // and getting a cheerful 200 without them.
  if (near) params.near = near;
  if (filters.cuisine) params.cuisine = filters.cuisine;
  if (filters.price_range) params.price_range = filters.price_range;
  if (filters.card) params.card = filters.card;
  if (filters.tags && filters.tags.length > 0) params['tags[]'] = filters.tags;
  if (filters.list) params.list = Number(filters.list.id);
  if (filters.filter) params.filter = filters.filter;

  const { data } = await api.get<MapResponse>('/map/places', { params });

  return {
    pins: data.data.pins,
    clusters: data.data.clusters,
    truncated: data.meta.truncated ?? false,
    fetchedAt: Date.now(),
  };
}

/**
 * Viewport-driven map fetch (T-032). The caller passes a region that only
 * updates on `onRegionChangeComplete` (debounced) — never per gesture frame.
 * The query key is the *quantized* bbox + zoom band + filters, so tiny pans
 * reuse the cache; `keepPreviousData` keeps old pins on screen (no blink)
 * while a new region loads. The public map works logged-out.
 *
 * `viewer` is the viewer's own position (T-156). It is sent as `near`, which is
 * what makes the pins carry `distance_m`. It is deliberately NOT part of the
 * cache key — see `queryKeys.mapPlaces`, where a persisted key under a
 * coordinate broke the offline cold start — so a fix arriving after the first
 * fetch refetches explicitly, in the effect below. Null (no permission, no fix)
 * omits the parameter, and `distance_m` with it.
 */
export function useMapPlaces(region: Region | null, filters: MapFilters, viewer: ViewerPoint | null = null) {
  const meta = region ? mapQueryFor(region) : null;
  const near = nearParam(viewer);
  const query = useQuery({
    queryKey: meta ? queryKeys.mapPlaces(meta.quantized, meta.band, filters) : ['places', 'map', 'idle'],
    queryFn: () => fetchMapPlaces(region!, filters, near),
    enabled: region !== null,
    staleTime: 120_000,
    placeholderData: keepPreviousData,
  });

  // `near` is not in the key (see queryKeys.mapPlaces for why), so a fix that
  // arrives after the first fetch has to ask for itself. Skipped on the first
  // run: the query is already fetching, and refetching it immediately would
  // double every map open.
  const { refetch } = query;
  const previousNear = useRef<string | null | undefined>(undefined);
  useEffect(() => {
    const first = previousNear.current === undefined;
    const changed = previousNear.current !== near;
    previousNear.current = near;
    if (!first && changed) void refetch();
  }, [near, refetch]);

  // The age of the rows ON SCREEN — see `MapData.fetchedAt` for why this is not
  // `dataUpdatedAt`. Zero when there is nothing yet, which the sheet reads as
  // "age unknown" and therefore shows no cue: the honest direction.
  return { ...query, fetchedAt: query.data?.fetchedAt ?? 0 };
}
