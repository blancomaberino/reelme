import AsyncStorage from '@react-native-async-storage/async-storage';
import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister';
import { defaultShouldDehydrateQuery, type Query } from '@tanstack/react-query';

/**
 * Query-cache persistence (T-103) — "where was that restaurant I saved?" has to
 * answer on a subway platform, so a cold start with no network must paint the
 * user's own places from disk instead of an empty map and a spinner.
 *
 * The scope is an ALLOWLIST, not a filter (see `isPersistableKey`): what lands
 * on disk is the viewer's own data only. Other people's profiles and the public
 * discovery surfaces are deliberately memory-only — they are not the offline
 * use case, and caching them would leave third-party data on the device long
 * after it went stale.
 */

/** How long a restored entry stays usable before it is dropped on rehydrate. */
const CACHE_MAX_AGE = 24 * 60 * 60 * 1000;

/** Storage key for the whole dehydrated client. */
const CACHE_KEY = 'reelmap-query-cache';

/**
 * Bump when a persisted payload shape changes (a Resource field rename, a query
 * key reshuffle). A mismatched buster makes the restore a no-op instead of
 * rehydrating data the current code can't read.
 */
// NOT bumped for T-168, deliberately. Hours are now generated in the request's
// locale, so a payload persisted before it holds lines in another language — but
// bumping this discards EVERY persisted cache for every user, including the one
// that restores a session offline (`offline-cold-start.test.tsx` fails on the
// bump, which is the blast radius made visible). A stale language is cosmetic
// and heals on the next fetch; a cold start that drops to guest is not. The
// targeted fix lives in `setLocale`, which invalidates the affected queries when
// the language actually changes.
const CACHE_BUSTER = 'v1';

/**
 * AsyncStorage with every call swallowed on failure. The native module only
 * exists once the dev client has been rebuilt (T-103 adds it), and a persister
 * that throws would take the provider — and therefore the app — down at boot.
 * Degrading to "no persisted cache" is always survivable.
 */
const storage = {
  getItem: async (key: string): Promise<string | null> => {
    try {
      return await AsyncStorage.getItem(key);
    } catch {
      return null;
    }
  },
  setItem: async (key: string, value: string): Promise<void> => {
    try {
      await AsyncStorage.setItem(key, value);
    } catch {
      // Disk full / native module missing — the in-memory cache still works.
    }
  },
  removeItem: async (key: string): Promise<void> => {
    try {
      await AsyncStorage.removeItem(key);
    } catch {
      // See above.
    }
  },
};

export const queryPersister = createAsyncStoragePersister({
  storage,
  key: CACHE_KEY,
  throttleTime: 2_000,
});

/**
 * Wipe the persisted cache from disk. Called on sign-out and on a 401: the
 * cache holds the viewer's private collection in plaintext, so the next person
 * to use the device must not be able to read it back.
 */
export async function clearPersistedQueryCache(): Promise<void> {
  await queryPersister.removeClient();
}

/**
 * The allowlist. `key` is a React Query key from `api/keys.ts`.
 *
 * Persisted:
 *  - `['me', …]` — the viewer's own profile, my-places pages, tags and facets.
 *  - `['places', 'map', …]` — ONLY the personal map (`filter: 'mine'`) or a map
 *    scoped to one of the viewer's own lists. The logged-out/public map is not
 *    the offline case.
 *  - `['places', <slug>]` / `[…, 'sources']` — place details already opened.
 *  - `['lists', …]` — the viewer's own lists, but never `['lists','public',…]`.
 *
 * Everything else — other users' profiles, the feed, search results, the tag
 * catalog, Tonight, share-pipeline status — stays memory-only. The discovery
 * surfaces are memory-only DELIBERATELY, and doubly so for the ones keyed by
 * the viewer's position: a rehydrated "open now" list is a fabricated open.
 */
export function isPersistableKey(key: readonly unknown[]): boolean {
  const [head, second] = key;

  // ...except the quota snapshot. It is a fact about RIGHT NOW on a 24h clock,
  // and the persisted cache lives for 24h — so a `remaining: 0` from yesterday
  // rehydrates on a cold start and disables the share button with a reset time
  // that has already passed. Offline (the case persistence exists for) the
  // refetch never resolves, so it stays disabled for the whole session. The
  // guard is meant to fail OPEN; persisting it makes it fail closed.
  if (head === 'me') return second !== 'quotas';

  if (head === 'lists') return second !== 'public';

  if (head === 'places') {
    // `['places','map', quantizedBbox, zoomBand, filters]` — index 4 is the
    // filter object the key was built from.
    if (second === 'map') return isOwnMapScope(key[4]);

    // Everything else under this head is allowed by SHAPE, not by name, and
    // that is a fix rather than a style choice. This used to deny-list the
    // public slices by their second segment (`'tag'`, `'payment-cards'`) and
    // let everything else through as `typeof second === 'string'` — so
    // `['places','tonight', near, radiusM, dish, openNow]` (T-158) matched the
    // branch written for `['places', <slug>]` and persisted a discovery slice
    // keyed by the viewer's own coordinate, in plaintext, for the cache's 24h.
    // An enumeration of the queries we happened to have is not a rule; the next
    // one to be added inherits the wrong answer, silently, exactly as this one
    // did through four review rounds.
    //
    // A place DETAIL is two segments, or three ending in `sources`. A discovery
    // QUERY carries its parameters, so it is always longer. Length is the thing
    // that actually distinguishes them.
    if (key.length === 3) return key[2] === 'sources';
    if (key.length !== 2) return false;

    // One name still has to be reserved: `['places','payment-cards']` is a
    // public list shaped exactly like a slug, and no structural rule can tell
    // them apart. It is listed here rather than inferred, and it is the only
    // one — a second entry appearing under this line means the shapes have
    // drifted and this branch, not the list, is what needs revisiting.
    return typeof second === 'string' && second !== 'payment-cards';
  }

  return false;
}

/** True when a map query key's filters scope it to the viewer's own places. */
function isOwnMapScope(filters: unknown): boolean {
  if (typeof filters !== 'object' || filters === null) return false;
  const { filter, list } = filters as { filter?: unknown; list?: unknown };
  return filter === 'mine' || (typeof list === 'object' && list !== null);
}

/**
 * `shouldDehydrateQuery` for the persist options: the library default (success
 * only, nothing still fetching) AND the allowlist above.
 */
export function shouldDehydrateQuery(query: Query): boolean {
  return defaultShouldDehydrateQuery(query) && isPersistableKey(query.queryKey);
}

export const persistOptions = {
  persister: queryPersister,
  maxAge: CACHE_MAX_AGE,
  buster: CACHE_BUSTER,
  dehydrateOptions: { shouldDehydrateQuery },
};
