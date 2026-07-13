// Central query-key factory — never inline string keys (05-mobile-app §1.3).

/** Map filters that participate in the cache key (T-032). */
export type MapFilters = {
  cuisine?: string | null;
  price_range?: number | null;
  tags?: string[];
  /** Restrict the map to a single owned place list (T-062). */
  list?: { id: string; name: string } | null;
};

export const queryKeys = {
  me: ['me'] as const,
  place: (slug: string) => ['places', slug] as const,
  placeSources: (slug: string) => ['places', slug, 'sources'] as const,
  // Quantized bbox + banded zoom keep tiny pans on one cache entry (T-032).
  mapPlaces: (quantizedBbox: string, zoomBand: number, filters: MapFilters) =>
    ['places', 'map', quantizedBbox, zoomBand, filters] as const,
  feed: (scope: string) => ['feed', scope] as const,
  search: (q: string, types: string) => ['search', q, types] as const,
  tagsPopular: () => ['tags', 'popular'] as const,
  placesByTag: (slug: string) => ['places', 'tag', slug] as const,
  share: (id: string) => ['shares', id] as const,
  lists: () => ['lists'] as const,
  list: (id: string) => ['lists', id] as const,
};
