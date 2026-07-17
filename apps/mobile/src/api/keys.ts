// Central query-key factory — never inline string keys (05-mobile-app §1.3).

/** "My places" list facet filters that participate in the cache key (T-071). */
export type MyPlacesFilters = {
  /** ISO 3166-1 alpha-2 country code, or null for all. */
  country?: string | null;
  /** cuisine_primary ("type"), or null for all. */
  type?: string | null;
  tags?: string[];
  sort?: 'recent' | 'popular';
};

/** Map filters that participate in the cache key (T-032). */
export type MapFilters = {
  cuisine?: string | null;
  price_range?: number | null;
  /** Card/bank/wallet with a payment discount (T-079), or null for all. */
  card?: string | null;
  tags?: string[];
  /** Restrict the map to a single owned place list (T-062). */
  list?: { id: string; name: string } | null;
  /** Scope the map to who you follow / your own shares (T-039). Authed only. */
  filter?: 'following' | 'mine' | null;
};

export const queryKeys = {
  me: ['me'] as const,
  place: (slug: string) => ['places', slug] as const,
  placeSources: (slug: string) => ['places', slug, 'sources'] as const,
  // Quantized bbox + banded zoom keep tiny pans on one cache entry (T-032).
  mapPlaces: (quantizedBbox: string, zoomBand: number, filters: MapFilters) =>
    ['places', 'map', quantizedBbox, zoomBand, filters] as const,
  feed: (scope: string) => ['feed', scope] as const,
  /** The personal "my places" list (T-071), keyed by its active facet filters. */
  myPlaces: (filters: MyPlacesFilters) => ['me', 'places', filters] as const,
  /** Prefix covering every my-places facet variant — for invalidation. */
  myPlacesAll: () => ['me', 'places'] as const,
  /** Prefix covering every map viewport/filter entry — for invalidation. */
  mapAll: () => ['places', 'map'] as const,
  search: (q: string, types: string) => ['search', q, types] as const,
  tagsPopular: () => ['tags', 'popular'] as const,
  /** Distinct payment-discount cards for the map filter (T-079). */
  paymentCards: () => ['places', 'payment-cards'] as const,
  placesByTag: (slug: string) => ['places', 'tag', slug] as const,
  share: (id: string) => ['shares', id] as const,
  lists: () => ['lists'] as const,
  list: (id: string) => ['lists', id] as const,
  /** A public, shared list keyed by its global public_slug (T-063). */
  publicList: (slug: string) => ['lists', 'public', slug] as const,
  /** Another user's public profile + viewer follow state (T-039). */
  profile: (username: string) => ['profile', username] as const,
  followers: (username: string) => ['profile', username, 'followers'] as const,
  following: (username: string) => ['profile', username, 'following'] as const,
  /** A user's public places list + public Lists shown on their profile (T-071). */
  userPlaces: (username: string) => ['profile', username, 'places'] as const,
  userLists: (username: string) => ['profile', username, 'lists'] as const,
};
