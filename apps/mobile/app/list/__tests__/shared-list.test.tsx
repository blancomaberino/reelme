import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import SharedListScreen from '../[slug]';
import { api } from '@/api/client';
import type { PublicPlaceList } from '@/api/lists';
import type { PlaceSummary } from '@/api/places';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function place(id: string, name: string): PlaceSummary {
  return {
    id,
    name,
    slug: `${name.toLowerCase()}-${id}`,
    status: 'active',
    lat: -34.9,
    lng: -56.16,
    category: 'modern',
    price_range: 2,
    city: 'Montevideo',
    country_code: 'UY',
    source_count: 1,
    rating: { google: { value: null, count: 0 } },
    distance_m: null,
    created_at: null,
  };
}

function fakeMe(id: string): Me {
  return {
    id,
    name: 'Viewer',
    username: 'viewer',
    email: 'v@example.com',
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
    email_verified_at: null,
    created_at: null,
  };
}

const LIST: PublicPlaceList = {
  id: '5',
  name: 'Lisbon food',
  slug: 'lisbon-food',
  public_slug: 'lisbon-food-x7k2ab',
  is_public: true,
  owner: { id: '1', username: 'marce', name: 'Marce', avatar_path: null },
  items_count: 2,
  items: [
    { note: null, position: 1, place: place('7', 'Clara') },
    { note: null, position: 2, place: place('9', 'Manteigaria') },
  ],
  created_at: null,
  updated_at: null,
};

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { slug: LIST.public_slug as string };
  mockRouter.replace.mockClear();
  useSessionStore.setState({ user: null, status: 'guest' });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('renders a shared list with owner attribution and places (guest: no save-a-copy)', async () => {
  mock.onGet(`/lists/${LIST.public_slug}`).reply(200, { data: LIST });

  render(<SharedListScreen />, { wrapper: Providers });

  expect(await screen.findByText('Clara')).toBeOnTheScreen();
  expect(screen.getByText('Manteigaria')).toBeOnTheScreen();
  expect(screen.getByText('Shared by @marce')).toBeOnTheScreen();
  // A guest cannot save a copy.
  expect(screen.queryByLabelText('Save a copy')).toBeNull();
});

it('lets an authed non-owner save a copy → POST + navigates to the new list', async () => {
  useSessionStore.setState({ user: fakeMe('99'), status: 'authed' });
  mock.onGet(`/lists/${LIST.public_slug}`).reply(200, { data: LIST });
  let posted = false;
  mock.onPost(`/me/lists/${LIST.public_slug}/copy`).reply(() => {
    posted = true;
    return [201, { data: { ...LIST, id: '42', owner: undefined } }];
  });

  render(<SharedListScreen />, { wrapper: Providers });

  fireEvent.press(await screen.findByLabelText('Save a copy'));

  await waitFor(() => expect(posted).toBe(true));
  await waitFor(() =>
    expect(mockRouter.replace).toHaveBeenCalledWith(
      expect.objectContaining({ pathname: '/lists/[id]', params: expect.objectContaining({ id: '42' }) }),
    ),
  );
});

it('hides save-a-copy from the list owner', async () => {
  // The signed-in user IS the owner (id '1').
  useSessionStore.setState({ user: fakeMe('1'), status: 'authed' });
  mock.onGet(`/lists/${LIST.public_slug}`).reply(200, { data: LIST });

  render(<SharedListScreen />, { wrapper: Providers });

  expect(await screen.findByText('Clara')).toBeOnTheScreen();
  expect(screen.queryByLabelText('Save a copy')).toBeNull();
});

it('shows a not-available state when the list is private or missing', async () => {
  mock.onGet(`/lists/${LIST.public_slug}`).reply(404);

  render(<SharedListScreen />, { wrapper: Providers });

  expect(await screen.findByText('List not available')).toBeOnTheScreen();
});
