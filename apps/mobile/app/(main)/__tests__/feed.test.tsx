import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import FeedScreen from '../feed';
import { api } from '@/api/client';
import type { FeedItem } from '@/api/places';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function feedItem(id: string, over: Partial<FeedItem['place']> = {}): FeedItem {
  return {
    id,
    published_at: '2026-07-12T20:00:00Z',
    sharer: { id: '6', username: 'foodie', name: 'Foodie', avatar_path: null },
    source_post: { platform: 'instagram', url: 'https://ig.com/x', caption: 'c', thumbnail_url: null },
    influencer: { id: '2', platform: 'instagram', handle: 'comeren.uy', display_name: 'comeren.uy', avatar_url: null },
    place: {
      id,
      name: `Place ${id}`,
      slug: `place-${id}`,
      status: 'pending',
      lat: 0,
      lng: 0,
      category: 'ramen',
      price_range: 2,
      city: 'Montevideo',
      country_code: 'UY',
      source_count: 1,
      rating: { google: { value: null, count: 0 } },
      distance_m: null,
      created_at: null,
      ...over,
    },
  };
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('renders feed cards with place + attribution', async () => {
  mock.onGet('/feed').reply(200, {
    data: [feedItem('1'), feedItem('2')],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });

  render(<FeedScreen />, { wrapper: Providers });

  expect(await screen.findByText('Place 1')).toBeOnTheScreen();
  expect(screen.getByText('Place 2')).toBeOnTheScreen();
  expect(screen.getAllByText('@comeren.uy').length).toBeGreaterThan(0);
});

it('taps a card through to the place detail', async () => {
  mock.onGet('/feed').reply(200, {
    data: [feedItem('1')],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });

  render(<FeedScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByLabelText('Place 1'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: 'place-1' } });
});

it('shows the empty state when there are no shares', async () => {
  mock.onGet('/feed').reply(200, {
    data: [],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });

  render(<FeedScreen />, { wrapper: Providers });
  expect(await screen.findByText('Nothing here yet')).toBeOnTheScreen();
});

it('opens search from the feed header', async () => {
  mock.onGet('/feed').reply(200, {
    data: [feedItem('1')],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });

  render(<FeedScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  fireEvent.press(screen.getByLabelText('Search'));
  expect(mockRouter.push).toHaveBeenCalledWith('/search');
});

it('shows an error state on failure', async () => {
  mock.onGet('/feed').reply(500);

  render(<FeedScreen />, { wrapper: Providers });
  expect(await screen.findByText(/Couldn’t load the feed/)).toBeOnTheScreen();
});

it('hides a card optimistically and offers undo when authed', async () => {
  useSessionStore.setState({ status: 'authed' });
  mock.onGet('/feed').reply(200, {
    data: [feedItem('1'), feedItem('2')],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });
  let posted = false;
  mock.onPost('/feed/hidden').reply(() => {
    posted = true;
    return [201];
  });

  render(<FeedScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');

  const hideButtons = screen.getAllByLabelText('Hide from my feed');
  fireEvent.press(hideButtons[0]);

  // Once the dismissal POSTs, the optimistic cache write has run: the card is
  // gone (but the sibling stays) and an undo snackbar is offered.
  await waitFor(() => expect(posted).toBe(true));
  expect(screen.queryByText('Place 1')).toBeNull();
  expect(screen.getByText('Place 2')).toBeOnTheScreen();
  expect(screen.getByText('Hidden from your feed')).toBeOnTheScreen();
  expect(screen.getByText('Undo')).toBeOnTheScreen();
});

it('does not show the hide control for guests', async () => {
  mock.onGet('/feed').reply(200, {
    data: [feedItem('1')],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });

  render(<FeedScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  expect(screen.queryByLabelText('Hide from my feed')).toBeNull();
});

it('appends the next page when the list reaches its end', async () => {
  mock
    .onGet('/feed', { params: { scope: 'global', limit: 20 } })
    .reply(200, {
      data: [feedItem('1')],
      meta: { pagination: { next_cursor: 'CUR', prev_cursor: null, limit: 20 } },
    });
  mock
    .onGet('/feed', { params: { scope: 'global', limit: 20, cursor: 'CUR' } })
    .reply(200, {
      data: [feedItem('2')],
      meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
    });

  render(<FeedScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  expect(screen.queryByText('Place 2')).toBeNull();

  // The FlashList mock exposes onEndReached via this control.
  fireEvent.press(screen.getByTestId('flash-list-end'));
  expect(await screen.findByText('Place 2')).toBeOnTheScreen();
});
