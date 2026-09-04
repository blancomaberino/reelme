import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { bboxParam, mapQueryFor, type Region } from '@/lib/geo';
import type { ViewerPoint } from '@/lib/use-viewer-position';

import { api } from '../client';
import { type MapFilters, queryKeys } from '../keys';
import type { MapResponse } from '../places';

/**
 * Decimal places kept on the `near` the map sends and keys its cache by.
 *
 * Four is ~11 m at this latitude — finer than any distance label the sheet
 * renders, and coarse enough that a phone sitting still on a table (whose fix
 * wanders a few metres) does not mint a fresh cache entry, and a fresh request,
 * every time it twitches.
 */
const NEAR_PRECISION = 4;

/** The `near=lat,lng` a request carries, or null when the viewer shared none. */
export function nearParam(viewer: ViewerPoint | null): string | null {
  if (!viewer) return null;

  return `${viewer.latitude.toFixed(NEAR_PRECISION)},${viewer.longitude.toFixed(NEAR_PRECISION)}`;
}

export type MapData = {
  pins: MapResponse['data']['pins'];
  clusters: MapResponse['data']['clusters'];
  truncated: boolean;
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
  return { pins: data.data.pins, clusters: data.data.clusters, truncated: data.meta.truncated ?? false };
}

/**
 * Viewport-driven map fetch (T-032). The caller passes a region that only
 * updates on `onRegionChangeComplete` (debounced) — never per gesture frame.
 * The query key is the *quantized* bbox + zoom band + filters, so tiny pans
 * reuse the cache; `keepPreviousData` keeps old pins on screen (no blink)
 * while a new region loads. The public map works logged-out.
 *
 * `viewer` is the viewer's own position (T-156). It is sent as `near`, which is
 * what makes the pins carry `distance_m` and `open_state` — and it is part of
 * the cache key, so a pan re-asks WITH it rather than replaying a distance-less
 * page. Null (no permission, no fix) simply omits the parameter and every
 * viewer-relative field with it.
 */
export function useMapPlaces(region: Region | null, filters: MapFilters, viewer: ViewerPoint | null = null) {
  const meta = region ? mapQueryFor(region) : null;
  const near = nearParam(viewer);
  return useQuery({
    queryKey: meta ? queryKeys.mapPlaces(meta.quantized, meta.band, filters, near) : ['places', 'map', 'idle'],
    queryFn: () => fetchMapPlaces(region!, filters, near),
    enabled: region !== null,
    staleTime: 120_000,
    placeholderData: keepPreviousData,
  });
}
