import type { PlaceSummary } from './places';

/** A place list in index form (GET /me/lists). */
export type PlaceListSummary = {
  id: string;
  name: string;
  slug: string;
  /** Global share token — non-null once the list has been made public (T-063). */
  public_slug: string | null;
  is_public: boolean;
  items_count: number;
  /** Present only when the index is queried with ?contains={placeId}. */
  contains?: boolean;
  created_at: string | null;
  updated_at: string | null;
};

/** One place in a list, with the owner's note. */
export type PlaceListItem = {
  note: string | null;
  position: number;
  place: PlaceSummary;
};

/** A list with its places (GET /me/lists/{id}, add/remove responses). */
export type PlaceListDetail = {
  id: string;
  name: string;
  slug: string;
  public_slug: string | null;
  is_public: boolean;
  items_count: number;
  items: PlaceListItem[];
  created_at: string | null;
  updated_at: string | null;
};

/** Compact owner attribution on a shared list (T-063). */
export type ListOwner = {
  id: string;
  username: string;
  name: string | null;
  avatar_path: string | null;
};

/**
 * A shared list read publicly (GET /lists/{public_slug}, T-063). Same as the
 * owner's detail plus owner attribution; only publicly-visible places appear.
 */
export type PublicPlaceList = PlaceListDetail & {
  owner: ListOwner | null;
};
