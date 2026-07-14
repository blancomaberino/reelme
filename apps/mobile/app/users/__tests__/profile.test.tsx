import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import UserProfileScreen from '../[username]/index';
import { api } from '@/api/client';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

function me(username: string): Me {
  return {
    id: '1', name: 'Me', username, email: 'me@example.com', avatar_path: null, bio: null, birthdate: null,
    age: null, favorite_topics: [], favorite_foods: [], is_influencer: false, is_restaurant_owner: false,
    is_admin: false, is_public: true, preferred_analysis_model: null, stripe_connect_onboarded: false,
    email_verified_at: '2026-07-14T00:00:00Z', created_at: null,
  };
}

function profileResponse(viewer: { following: boolean; follow_id: string | null }) {
  return {
    data: {
      profile: {
        id: '9', username: 'alice', name: 'Alice', bio: 'hi', avatar_path: null, is_influencer: false,
        counters: { published_shares: 3, followers: 12, following: 7 }, created_at: null,
      },
      shares: [],
    },
    meta: { viewer },
  };
}

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { username: 'alice' };
  mockRouter.push.mockClear();
  useSessionStore.setState({ user: me('bob'), status: 'authed' }); // authed, not self
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('renders a profile with counters and a Follow button', async () => {
  mock.onGet('/users/alice').reply(200, profileResponse({ following: false, follow_id: null }));

  render(<UserProfileScreen />, { wrapper: Providers });

  expect(await screen.findByText('Alice')).toBeOnTheScreen();
  expect(screen.getByText('12')).toBeOnTheScreen(); // followers
  expect(screen.getByLabelText('Follow')).toBeOnTheScreen();
});

it('follows via POST /follows', async () => {
  mock.onGet('/users/alice').reply(200, profileResponse({ following: false, follow_id: null }));
  let body: unknown = null;
  mock.onPost('/follows').reply((config) => {
    body = JSON.parse(config.data as string);
    return [201, { data: { id: '55' } }];
  });

  render(<UserProfileScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByLabelText('Follow'));

  await waitFor(() => expect(body).toEqual({ followable_type: 'user', followable_id: 9 }));
});

it('unfollows via DELETE /follows/{id} when already following', async () => {
  mock.onGet('/users/alice').reply(200, profileResponse({ following: true, follow_id: '55' }));
  let deleted: string | null = null;
  mock.onDelete('/follows/55').reply(() => {
    deleted = '55';
    return [200, { data: null }];
  });

  render(<UserProfileScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByLabelText('Following'));

  await waitFor(() => expect(deleted).toBe('55'));
});

it('hides the follow button on your own profile', async () => {
  useSessionStore.setState({ user: me('alice'), status: 'authed' }); // self
  mock.onGet('/users/alice').reply(200, profileResponse({ following: false, follow_id: null }));

  render(<UserProfileScreen />, { wrapper: Providers });
  await screen.findByText('Alice');
  expect(screen.queryByLabelText('Follow')).toBeNull();
});
