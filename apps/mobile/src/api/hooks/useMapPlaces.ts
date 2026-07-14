import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { bboxParam, mapQueryFor, type Region } from '@/lib/geo';

import { api } from '../client';
import { type MapFilters, queryKeys } from '../keys';
import type { MapResponse } from '../places';

export type MapData = {
  pins: MapResponse['data']['pins'];
  clusters: MapResponse['data']['clusters'];
  truncated: boolean;
};

async function fetchMapPlaces(region: Region, filters: MapFilters): Promise<MapData> {
  const { bbox, zoom } = mapQueryFor(region);
  const params: Record<string, string | number | string[]> = { bbox: bboxParam(bbox), zoom };
  if (filters.cuisine) params.cuisine = filters.cuisine;
  if (filters.price_range) params.price_range = filters.price_range;
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
 */
export function useMapPlaces(region: Region | null, filters: MapFilters) {
  const meta = region ? mapQueryFor(region) : null;
  return useQuery({
    queryKey: meta ? queryKeys.mapPlaces(meta.quantized, meta.band, filters) : ['places', 'map', 'idle'],
    queryFn: () => fetchMapPlaces(region!, filters),
    enabled: region !== null,
    staleTime: 120_000,
    placeholderData: keepPreviousData,
  });
}
