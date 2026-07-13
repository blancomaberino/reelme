import type { PlaceSummary } from './places';

/** A place list in index form (GET /me/lists). */
export type PlaceListSummary = {
  id: string;
  name: string;
  slug: string;
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
  is_public: boolean;
  items_count: number;
  items: PlaceListItem[];
  created_at: string | null;
  updated_at: string | null;
};
