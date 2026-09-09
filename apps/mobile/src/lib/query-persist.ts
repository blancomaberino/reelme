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
// NOT bumped for T-158's `['places', <slug>]` → `['places','detail', <slug>]`
// rename either, and for the same reason as T-168 above. Entries written under
// the old shape restore under keys nothing asks for and fall out at the 24h
// cap; the cost is one cache miss per place detail, once, healing on the next
// fetch. A bump would discard EVERY persisted cache for every user to avoid
// that — including the session restore an offline cold start depends on.
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
 * surfaces are memory-only DELIBERATELY: a rehydrated "open now" list is a
 * fabricated open.
 *
 * What this function can and cannot promise. It reads the KEY, so it keeps a
 * query whose key carries the viewer's position off disk — that was T-158's bug
 * and it is closed. It does not read the VALUE, and review found the gap that
 * leaves: the own-scope map IS persisted, and each pin in that payload carries
 * `lat`/`lng` at 6 dp beside `distance_m` at 0.1 m, which trilaterates the
 * viewer's own position out of a payload that never mentions it. Pre-existing —
 * T-156 added the distances, T-103 the persistence — and not something a rule
 * about key shapes can see. Recorded rather than fixed here: closing it means
 * either stripping `distance_m` on dehydrate or dropping `near` from the
 * mine-scoped map, both of which change what the offline map can show.
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

    // An ALLOWLIST, and the third attempt at this branch — which is the reason
    // it is now shaped this way rather than patched again. It began as "any
    // string second segment is a place slug", with the public slices subtracted
    // by name (`'tag'`, `'payment-cards'`). That is a list of the queries we
    // happened to have, so every query added later inherited PERSIST by default:
    // T-158's `['places','tonight', near, …]` did exactly that, and shipped a
    // discovery slice keyed by the viewer's own coordinate to plaintext storage
    // for 24h. Replacing the list with a rule about key LENGTH fixed that one
    // and still left `['places','tag','sources']` passing and any future
    // parameterless list — `['places','trending']` — inheriting persist again.
    //
    // So the detail keys were moved under a `detail` namespace (see
    // `queryKeys.place`) and this asks the only question that cannot be
    // inherited by accident: is it a detail? Everything else under this head is
    // denied because it is not on the list, not because someone remembered it.
    //
    // Scope, honestly: this is true of `places` and NOT of the two branches
    // above. `me` and `lists` are still deny-lists (`!== 'quotas'`,
    // `!== 'public'`), so a new key under either still inherits PERSIST — the
    // same mechanism, untouched. Converting them means auditing every `me` key,
    // most of which SHOULD persist (the profile, my-places, its tags and
    // facets), so it is a change to offline behaviour rather than a rename, and
    // it does not belong in a T-158 audit response. Left for its own task.
    if (second !== 'detail') return false;

    // `['places','detail', slug]` and `['places','detail', slug, 'sources']`.
    return key.length === 3 || (key.length === 4 && key[3] === 'sources');
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
