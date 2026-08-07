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
 * catalog, share-pipeline status — stays memory-only.
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
    // Public discovery slices that happen to live under the same head.
    if (second === 'tag' || second === 'payment-cards') return false;
    // `['places', <slug>]` and `['places', <slug>, 'sources']`.
    return typeof second === 'string';
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
