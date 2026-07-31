import AsyncStorage from '@react-native-async-storage/async-storage';
import { QueryClient } from '@tanstack/react-query';
import { waitFor } from '@testing-library/react-native';

import { queryKeys } from '@/api/keys';
import {
  clearPersistedQueryCache,
  isPersistableKey,
  persistOptions,
  queryPersister,
  shouldDehydrateQuery,
} from '@/lib/query-persist';

import { mockAsyncStorage } from '../../../jest.setup';

/**
 * The persisted cache is the viewer's private collection sitting in plaintext
 * on the device, so what lands in it is an allowlist decided here (T-103). A
 * key that quietly starts persisting is a privacy regression, not a caching
 * one — hence a test per surface rather than a couple of samples.
 */
describe('isPersistableKey', () => {
  const mine = { filter: 'mine' as const, tags: [] };
  const publicScope = { filter: null, tags: [] };

  it.each([
    ['own profile', queryKeys.me],
    ['my places page', queryKeys.myPlaces({ sort: 'recent' })],
    ['my places facets', queryKeys.myPlacesFacets()],
    ['my places tags', queryKeys.myPlacesTags()],
    ['place detail', queryKeys.place('la-gran-burger')],
    ['place sources', queryKeys.placeSources('la-gran-burger')],
    ['own lists index', queryKeys.lists()],
    ['own list detail', queryKeys.list('7')],
    ['personal map', queryKeys.mapPlaces('bbox', 12, mine)],
    ['map scoped to one of my lists', queryKeys.mapPlaces('bbox', 12, { list: { id: '7', name: 'Tapas' } })],
  ])('persists the %s', (_label, key) => {
    expect(isPersistableKey(key)).toBe(true);
  });

  it.each([
    ["another user's profile", queryKeys.profile('ana')],
    ["another user's places", queryKeys.userPlaces('ana')],
    ['followers', queryKeys.followers('ana')],
    ['a shared public list', queryKeys.publicList('tapas-xyz')],
    ['the feed', queryKeys.feed('following')],
    ['search results', queryKeys.search('ramen', 'place')],
    ['the tag catalog', queryKeys.tagsCatalog()],
    ['places by tag', queryKeys.placesByTag('ramen')],
    ['payment cards', queryKeys.paymentCards()],
    ['share status', queryKeys.share('42')],
    ['the public map', queryKeys.mapPlaces('bbox', 12, publicScope)],
    ['the idle map placeholder', ['places', 'map', 'idle']],
  ])('does not persist %s', (_label, key) => {
    expect(isPersistableKey(key)).toBe(false);
  });
});

describe('shouldDehydrateQuery', () => {
  it('persists an allowlisted query only once it has succeeded', async () => {
    const client = new QueryClient();
    await client.prefetchQuery({ queryKey: queryKeys.me, queryFn: async () => ({ id: '1' }) });
    const [query] = client.getQueryCache().getAll();

    expect(shouldDehydrateQuery(query)).toBe(true);
  });

  it('refuses a failed query even on an allowlisted key', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await client
      .prefetchQuery({
        queryKey: queryKeys.me,
        queryFn: async () => {
          throw new Error('boom');
        },
      })
      .catch(() => {});
    const [query] = client.getQueryCache().getAll();

    expect(query.state.status).toBe('error');
    expect(shouldDehydrateQuery(query)).toBe(false);
  });

  it('refuses a successful query on a non-allowlisted key', async () => {
    const client = new QueryClient();
    await client.prefetchQuery({ queryKey: queryKeys.profile('ana'), queryFn: async () => ({ id: '2' }) });
    const [query] = client.getQueryCache().getAll();

    expect(shouldDehydrateQuery(query)).toBe(false);
  });
});

describe('persister', () => {
  it('round-trips a dehydrated client through storage and clears on demand', async () => {
    void queryPersister.persistClient({
      buster: 'v1',
      timestamp: 1,
      clientState: { mutations: [], queries: [] },
    });

    // The write is throttled, so it lands a tick after the call returns.
    await waitFor(() => expect(mockAsyncStorage.store.size).toBe(1));
    await expect(queryPersister.restoreClient()).resolves.toMatchObject({ buster: 'v1' });

    await clearPersistedQueryCache();

    expect(mockAsyncStorage.store.size).toBe(0);
    await expect(queryPersister.restoreClient()).resolves.toBeUndefined();
  });

  /**
   * Guards the wiring, not the constant. `persistOptions` is the whole of what
   * the provider receives, and an edit that dropped `dehydrateOptions` would
   * silently start persisting EVERY query — other people's profiles included —
   * with every other test in this file still green.
   */
  it('hands the provider the allowlist and a 24h window', () => {
    expect(persistOptions.persister).toBe(queryPersister);
    expect(persistOptions.maxAge).toBe(24 * 60 * 60 * 1000);
    expect(persistOptions.dehydrateOptions.shouldDehydrateQuery).toBe(shouldDehydrateQuery);
  });

  /**
   * The native module only exists once the dev client has been rebuilt with
   * it. A persister that throws would take the provider — and so the whole app
   * — down at boot, which is a far worse failure than having no cached data.
   */
  it('survives a storage layer that is not there', async () => {
    const missing = new Error('Native module RNCAsyncStorage is null');
    (AsyncStorage.getItem as jest.Mock).mockRejectedValueOnce(missing);
    (AsyncStorage.setItem as jest.Mock).mockRejectedValueOnce(missing);
    (AsyncStorage.removeItem as jest.Mock).mockRejectedValueOnce(missing);

    await expect(queryPersister.restoreClient()).resolves.toBeUndefined();
    await expect(clearPersistedQueryCache()).resolves.toBeUndefined();
    expect(() =>
      queryPersister.persistClient({ buster: 'v1', timestamp: 1, clientState: { mutations: [], queries: [] } }),
    ).not.toThrow();
  });
});
