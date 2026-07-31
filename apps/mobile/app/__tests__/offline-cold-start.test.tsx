import { render, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';

import RootLayout from '../_layout';
import { api } from '@/api/client';
import { queryClient } from '@/api/query-client';
import { clearToken, getToken, setToken } from '@/api/token';
import { useSessionStore } from '@/stores/session';

import { mockAsyncStorage } from '../../jest.setup';

/**
 * A cold start with no network (T-103). The auth gate hydrates the session with
 * GET /me; before this task ANY failure there wiped the token and dropped the
 * user to the welcome screen — so opening the app on a plane logged you out and
 * you couldn't log back in until you landed. The token is still valid when the
 * network is gone; we simply couldn't ask.
 */

// The layout mounts the share-intent provider; no share is waiting here.
jest.mock('expo-share-intent', () => ({
  ShareIntentProvider: ({ children }: { children: React.ReactNode }) => children,
  useShareIntentContext: () => ({
    hasShareIntent: false,
    shareIntent: { webUrl: null, text: null },
    resetShareIntent: jest.fn(),
  }),
  getShareExtensionKey: () => 'sharekey',
}));

const CACHE_KEY = 'reelmap-query-cache';

const CACHED_USER = { id: '1', name: 'Ada', username: 'ada', email: 'ada@example.com' };

/** What `PersistQueryClientProvider` finds on disk from a previous session. */
function seedPersistedMe() {
  mockAsyncStorage.store.set(
    CACHE_KEY,
    JSON.stringify({
      buster: 'v1',
      timestamp: Date.now(),
      clientState: {
        mutations: [],
        queries: [
          {
            queryKey: ['me'],
            queryHash: JSON.stringify(['me']),
            state: { data: CACHED_USER, dataUpdatedAt: Date.now(), status: 'success', fetchStatus: 'idle' },
          },
        ],
      },
    }),
  );
}

let mock: AxiosMockAdapter;

beforeEach(() => {
  mock = new AxiosMockAdapter(api);
  useSessionStore.setState({ user: null, status: 'loading' });
  // The root layout's client is a module singleton — one launch in production,
  // but several mounts per worker here. Reset it so each test starts genuinely
  // cold, otherwise a previous test's rehydrated ['me'] leaks in.
  queryClient.clear();
});

afterEach(async () => {
  mock.restore();
  await clearToken();
});

it('keeps the session and restores the user from cache when /me cannot be reached', async () => {
  await setToken('tok_1');
  seedPersistedMe();
  mock.onGet('/me').networkError();

  render(<RootLayout />);

  await waitFor(() => expect(useSessionStore.getState().status).toBe('authed'));
  expect(useSessionStore.getState().user?.username).toBe('ada');
  // The token survives — being offline is not a sign-out.
  expect(await getToken()).toBe('tok_1');
});

it('falls back to guest offline only when there is no cached user to restore', async () => {
  await setToken('tok_1');
  mock.onGet('/me').networkError();

  render(<RootLayout />);

  await waitFor(() => expect(useSessionStore.getState().status).toBe('guest'));
  // Still keeps the token: nothing has told us it is invalid, so a later
  // launch with a connection can complete the sign-in.
  expect(await getToken()).toBe('tok_1');
});

it('caches the bootstrap /me so the NEXT cold start has something to restore', async () => {
  // The gap this closes: the bootstrap fetch is imperative, so before T-103 the
  // ['me'] entry existed only right after a login — a returning user had
  // nothing persisted and fell straight through to the welcome screen the
  // first time they opened the app without a connection.
  await setToken('tok_1');
  mock.onGet('/me').reply(200, { data: { user: CACHED_USER } });

  render(<RootLayout />);

  await waitFor(() => expect(useSessionStore.getState().status).toBe('authed'));
  expect(queryClient.getQueryData(['me'])).toMatchObject({ username: 'ada' });
});

it('still ends the session when the API rejects the token', async () => {
  await setToken('tok_stale');
  seedPersistedMe();
  mock.onGet('/me').reply(401, { error: { code: 'unauthenticated' } });

  render(<RootLayout />);

  await waitFor(() => expect(useSessionStore.getState().status).toBe('guest'));
  expect(await getToken()).toBeNull();
  // A revoked session must not leave the account's collection behind — and both
  // halves matter: clearing only the disk copy leaves it in the live cache, and
  // the persister (subscribed to that cache) writes it straight back out.
  await waitFor(() => expect(mockAsyncStorage.store.has(CACHE_KEY)).toBe(false));
  expect(queryClient.getQueryData(['me'])).toBeUndefined();
  expect(queryClient.getQueryCache().getAll()).toHaveLength(0);
});
