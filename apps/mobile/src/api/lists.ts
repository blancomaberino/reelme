// Saved-collection API types (GET/POST /me/lists, the public read /lists/{slug}).
//
// All four response shapes are re-exported from @reelmap/contracts (T-102) —
// place-list.json / place-list-detail.json are the single source of truth shared
// with PlaceListResource / PlaceListDetailResource, so a renamed or removed API
// field breaks `tsc`, not the device.
import type {
  PlaceListDetail as ContractPlaceListDetail,
  PlaceListSummary as ContractPlaceListSummary,
  UserSummary,
} from '@reelmap/contracts';

/**
 * A place list in index form (GET /me/lists). `contains` is present only when
 * the index is queried with ?contains={placeId}; `public_slug` is the global
 * share token, non-null once the list has been made public (T-063).
 */
export type PlaceListSummary = ContractPlaceListSummary;

/** One place in a list, with the owner's note. */
export type PlaceListItem = ContractPlaceListDetail['items'][number];

/** A list with its places (GET /me/lists/{id}, add/remove responses). */
export type PlaceListDetail = ContractPlaceListDetail;

/** Compact owner attribution on a shared list (T-063). */
export type ListOwner = UserSummary;

/**
 * A shared list read publicly (GET /lists/{public_slug}, T-063). Same as the
 * owner's detail, except `owner` is always present — nulled when the owner's
 * profile is private. Only publicly-visible places appear.
 */
export type PublicPlaceList = ContractPlaceListDetail & {
  owner: ListOwner | null;
};
