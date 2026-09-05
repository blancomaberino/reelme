// Central query-key factory — never inline string keys (05-mobile-app §1.3).

/** "My places" list facet filters that participate in the cache key (T-071). */
export type MyPlacesFilters = {
  /** ISO 3166-1 alpha-2 country code, or null for all. */
  country?: string | null;
  /** cuisine_primary ("type"), or null for all. */
  type?: string | null;
  tags?: string[];
  /** Only places running an offer you could redeem right now (T-047). */
  hasOffers?: boolean;
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

/**
 * The key prefixes whose payloads the SERVER localizes — everything that must be
 * re-asked for when the app's language changes.
 *
 * It lives here, beside the factory, because that is where someone adding a
 * localized endpoint is already looking. The alternative — a literal in the
 * settings store — was wrong within one review: it invalidated `['places']`
 * alone, and missed tag labels (`TagResource.label` resolves `name_i18n` per
 * request) served under `['tags','catalog']`, `['me','places','tags']` and
 * `['search',…]`, plus `country_name` under `['me']` and the profile keys. The
 * visible result was a Spanish tag filter beside English hours — surviving cold
 * starts, because `['me','places','tags']` is persisted for 24h.
 *
 * Note `['me','places',…]` is NOT under the `places` prefix: the key layout and
 * the concept have already diverged once, which is exactly why this is a list
 * rather than a prefix match someone has to keep true in their head.
 *
 * Add an entry here when you add an endpoint that varies by `Accept-Language`.
 */
export const LOCALIZED_KEY_PREFIXES: readonly (readonly string[])[] = [
  ['places'],   // place detail: opening_hours lines are generated per locale (T-168)
  ['me'],       // my-places tags + facets, and country_name on the viewer
  ['tags'],     // the tag catalog's labels
  ['search'],   // search results carry tag labels
  ['profile'],  // another user's country_name
  ['influencer'],
] as const;

export const queryKeys = {
  me: ['me'] as const,
  /** Daily allowance from GET /me meta (T-051). Separate key: it goes stale on
   *  a clock the profile does not follow, and must not force a profile refetch. */
  quotas: () => ['me', 'quotas'] as const,
  /** Accounts the viewer has blocked (T-054). */
  blocks: () => ['me', 'blocks'] as const,
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
  /** The discovery-tag facet of my places, for the filter autocomplete (ADR-084). */
  myPlacesTags: () => ['me', 'places', 'tags'] as const,
  /** Country + type facets of my places over the FULL collection (T-088). */
  myPlacesFacets: () => ['me', 'places', 'facets'] as const,
  /** Prefix covering every map viewport/filter entry — for invalidation. */
  mapAll: () => ['places', 'map'] as const,
  search: (q: string, types: string) => ['search', q, types] as const,
  /** Broad tag catalog searched client-side by the filter autocomplete. */
  tagsCatalog: () => ['tags', 'catalog'] as const,
  /** Localized ISO 3166-1 country catalog (T-110). Keyed by locale because the
   *  whole payload is the localization — sharing one entry across languages
   *  would leave the picker in the previous language after a toggle. */
  countries: (locale: string) => ['countries', locale] as const,
  /** Distinct payment-discount cards for the map filter (T-079). */
  paymentCards: () => ['places', 'payment-cards'] as const,
  placesByTag: (slug: string) => ['places', 'tag', slug] as const,
  /**
   * Tonight (T-158). Every input is IN the key — that is what makes changing
   * the zone, the dish or the open-now toggle re-ask rather than re-render the
   * page already in hand.
   */
  tonight: (near: string, radiusM: number, dish: string, openNow: boolean) =>
    ['places', 'tonight', near, radiusM, dish, openNow] as const,
  share: (id: string) => ['shares', id] as const,
  /** The viewer's recent-shares list (ingest history), keyed by page size. */
  sharesList: (limit: number) => ['shares', 'list', limit] as const,
  /** Prefix covering every recent-shares page — for invalidation. */
  sharesListAll: () => ['shares', 'list'] as const,
  lists: () => ['lists'] as const,
  list: (id: string) => ['lists', id] as const,
  /** A public, shared list keyed by its global public_slug (T-063). */
  publicList: (slug: string) => ['lists', 'public', slug] as const,
  /** Another user's public profile + viewer follow state (T-039). */
  profile: (username: string) => ['profile', username] as const,
  /** An influencer's public profile + viewer follow state (T-039). */
  influencer: (id: string) => ['influencer', id] as const,
  /** The viewer's own claim on an influencer identity (T-038 flow, T-039 UI). */
  influencerClaim: (id: string) => ['influencer', id, 'claim'] as const,
  /** The notification center list (T-040) — one infinite query, badge included. */
  notifications: () => ['notifications'] as const,
  /** The operator's own offers across every venue they run (T-042). */
  myOffers: () => ['offers', 'mine'] as const,
  /** One offer, for the edit form. */
  offer: (id: string) => ['offers', id] as const,
  /** The venues the caller operates — a verified place claim each (T-041/T-042). */
  venues: () => ['me', 'venues'] as const,
  /** Corrections still awaiting moderation on those venues (T-083). Nested under
   *  `venues` so approving one and re-fetching the venue list stay one prefix. */
  venueSuggestions: () => ['me', 'venues', 'suggestions'] as const,
  /** One redemption, polled while it is live (T-047). */
  redemption: (id: string) => ['redemptions', id] as const,
  /** The diner's own codes. */
  myRedemptions: () => ['redemptions', 'mine'] as const,
  /** A venue's redemption log (T-047 verify invalidates it). */
  placeRedemptions: () => ['redemptions', 'place'] as const,
  /**
   * The device's own position (T-047). Cached as a query so the offers browse
   * does not re-prompt for a fix on every visit, and so the retry after a
   * refusal is a refetch rather than a second code path.
   */
  deviceLocation: () => ['device', 'location'] as const,
  /** Nearby active offers for the diner browse (T-047). */
  nearbyOffers: (near: string, radiusM: number) => ['offers', 'nearby', near, radiusM] as const,
  /** Balance, Connect state and recent entries (T-046). Never cached — money. */
  wallet: () => ['wallet'] as const,
  walletLedger: () => ['wallet', 'ledger'] as const,
  walletPayouts: () => ['wallet', 'payouts'] as const,
  /**
   * The influencer earnings funnel (T-048), keyed by its window — the three
   * periods are three different answers, so they must not share a cache entry.
   */
  influencerDashboard: (period: string) => ['wallet', 'dashboard', period] as const,
  /** The selectable analysis-model catalog (T-020). */
  analysisModels: () => ['analysis', 'models'] as const,
  /** The caller's 2FA state (T-068). Not nested under `me` — invalidating the
   *  profile must not re-fetch it, and vice versa. */
  twoFactor: () => ['two-factor'] as const,
  followers: (username: string) => ['profile', username, 'followers'] as const,
  following: (username: string) => ['profile', username, 'following'] as const,
  /** A user's public places list + public Lists shown on their profile (T-071). */
  userPlaces: (username: string) => ['profile', username, 'places'] as const,
  userLists: (username: string) => ['profile', username, 'lists'] as const,
};
