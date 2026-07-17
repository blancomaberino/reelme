import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import TagResultsScreen from '../[slug]';
import { api } from '@/api/client';
import type { PlaceSummary } from '@/api/places';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function place(id: string): PlaceSummary {
  return {
    id,
    name: `Place ${id}`,
    slug: `place-${id}`,
    status: 'pending',
    lat: 0,
    lng: 0,
    category: 'ramen',
    price_range: 2,
    city: 'Tokyo',
    country_code: 'JP',
    source_count: 1,
    rating: { google: { value: null, count: 0 } },
    distance_m: null,
    created_at: null,
  };
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { slug: 'noodles' };
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('lists places for the tag and taps through to detail', async () => {
  mock.onGet('/places').reply(200, {
    data: [place('1')],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });

  render(<TagResultsScreen />, { wrapper: Providers });

  // The header localizes + title-cases the tag (locale is pinned to en in tests).
  expect(await screen.findByText('#Noodles')).toBeOnTheScreen();
  fireEvent.press(await screen.findByLabelText('Place 1'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: 'place-1' } });
});

it('appends the next page on end-reached', async () => {
  mock
    .onGet('/places', { params: { 'tags[]': ['noodles'], limit: 20 } })
    .reply(200, { data: [place('1')], meta: { pagination: { next_cursor: 'C', prev_cursor: null, limit: 20 } } });
  mock
    .onGet('/places', { params: { 'tags[]': ['noodles'], limit: 20, cursor: 'C' } })
    .reply(200, { data: [place('2')], meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } } });

  render(<TagResultsScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  expect(screen.queryByText('Place 2')).toBeNull();

  fireEvent.press(screen.getByTestId('flash-list-end'));
  expect(await screen.findByText('Place 2')).toBeOnTheScreen();
});

it('shows an empty state when no places carry the tag', async () => {
  mock.onGet('/places').reply(200, {
    data: [],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });

  render(<TagResultsScreen />, { wrapper: Providers });
  expect(await screen.findByText(/No places for this tag/)).toBeOnTheScreen();
});
