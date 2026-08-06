import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ProfileScreen from '../profile';
import { api } from '@/api/client';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { mockRouter } from '../../../jest.setup';

/**
 * The own-profile social counters (T-039).
 *
 * This screen shipped as an M0 shell — a list of links and a note reading
 * "your shares, followers & settings land here (T-039)" — and stayed that way
 * while the task was marked done, because every test only ever asserted on the
 * rows that DID exist. The counters are the acceptance criterion, so they get
 * asserted here, including that they lead somewhere.
 *
 * They come from `GET /users/{username}` rather than `/me`: see the comment on
 * the screen. A private account is readable by its owner, so this is not a
 * public-only feature — the `is_public: false` case is covered below.
 */

let qc: QueryClient;
let mock: AxiosMockAdapter;

function me(overrides: Partial<Me> = {}): Me {
  return {
    id: '1',
    name: 'Ana',
    username: 'ana',
    email: 'ana@example.com',
    avatar_path: null,
    bio: null,
    birthdate: null,
    age: null,
    favorite_topics: [],
    favorite_foods: [],
    is_influencer: false,
    is_restaurant_owner: false,
    is_admin: false,
    is_public: true,
    preferred_analysis_model: null,
    stripe_connect_onboarded: false,
    email_verified_at: '2026-01-01T00:00:00Z',
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

function profileBody(counters: { followers: number; following: number; published_shares: number }) {
  return {
    data: {
      profile: {
        id: '1',
        username: 'ana',
        name: 'Ana',
        bio: null,
        avatar_path: null,
        is_influencer: false,
        counters,
        created_at: '2026-01-01T00:00:00Z',
      },
    },
    meta: { viewer: { following: false, follow_id: null } },
  };
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  // `pagination` is NOT optional padding: useNotifications' getNextPageParam
  // reads meta.pagination.next_cursor on every render, so a fixture without
  // it throws inside the screen the moment ANY other query re-renders it.
  mock
    .onGet('/notifications')
    .reply(200, { data: [], meta: { unread_count: 0, pagination: { next_cursor: null } } });
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('shows my followers, following and share counts', async () => {
  useSessionStore.setState({ user: me(), status: 'authed' });
  mock.onGet('/users/ana').reply(200, profileBody({ followers: 12, following: 7, published_shares: 3 }));

  render(<ProfileScreen />, { wrapper });

  await screen.findByTestId('profile-counter-followers');
  expect(screen.getByText('12')).toBeOnTheScreen();
  expect(screen.getByText('7')).toBeOnTheScreen();
  expect(screen.getByText('3')).toBeOnTheScreen();
});

it('opens my followers and following lists', async () => {
  useSessionStore.setState({ user: me(), status: 'authed' });
  mock.onGet('/users/ana').reply(200, profileBody({ followers: 12, following: 7, published_shares: 3 }));

  render(<ProfileScreen />, { wrapper });

  fireEvent.press(await screen.findByTestId('profile-counter-followers'));
  expect(mockRouter.push).toHaveBeenCalledWith({
    pathname: '/users/[username]/followers',
    params: { username: 'ana' },
  });

  fireEvent.press(screen.getByTestId('profile-counter-following'));
  expect(mockRouter.push).toHaveBeenCalledWith({
    pathname: '/users/[username]/following',
    params: { username: 'ana' },
  });
});

it('shows them on a PRIVATE account too — the owner can always read their own profile', async () => {
  // The endpoint 404s a private profile for everyone but its owner, so a
  // reasonable-looking "only fetch when is_public" guard would silently blank
  // the counters for exactly the users most likely to check them.
  useSessionStore.setState({ user: me({ is_public: false }), status: 'authed' });
  mock.onGet('/users/ana').reply(200, profileBody({ followers: 4, following: 1, published_shares: 0 }));

  render(<ProfileScreen />, { wrapper });

  await screen.findByTestId('profile-counter-followers');
  expect(screen.getByText('4')).toBeOnTheScreen();
});

it('renders nothing rather than zeros while the counts are still loading', async () => {
  useSessionStore.setState({ user: me(), status: 'authed' });
  mock.onGet('/users/ana').reply(500);

  render(<ProfileScreen />, { wrapper });

  // A row of confident 0s is worse than no row: it reads as "you have no
  // followers" when the truth is "we could not ask".
  await waitFor(() => expect(mock.history.get.some((r) => r.url === '/users/ana')).toBe(true));
  expect(screen.queryByTestId('profile-counter-followers')).toBeNull();
});

it('no longer tells the user their profile is unfinished', () => {
  useSessionStore.setState({ user: me(), status: 'authed' });
  mock.onGet('/users/ana').reply(200, profileBody({ followers: 0, following: 0, published_shares: 0 }));

  render(<ProfileScreen />, { wrapper });

  // The T-039 placeholder shipped to users for months. It must not come back.
  expect(screen.queryByText(/T-039/)).toBeNull();
});
