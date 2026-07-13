import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import SearchScreen from '../search';
import { api } from '@/api/client';

import { mockRouter } from '../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

const RESULTS = {
  data: {
    places: [
      {
        id: '5',
        name: 'Lanzhou Noodle House',
        slug: 'lanzhou-noodle-house-6stwbu',
        status: 'pending',
        lat: 0,
        lng: 0,
        category: 'chinese',
        price_range: 2,
        city: 'Tokyo',
        country_code: 'JP',
        source_count: 1,
        rating: { google: { value: null, count: 0 } },
        distance_m: null,
        created_at: null,
      },
    ],
    tags: [{ id: '1', kind: 'dish', name: 'Noodles', slug: 'noodles' }],
    influencers: [],
  },
  meta: { query: 'nood', took_ms: 1 },
};

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  jest.useFakeTimers();
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/search').reply(200, RESULTS);
  mockRouter.push.mockClear();
  mockRouter.back.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
  jest.useRealTimers();
});

it('debounces rapid typing into a single request', async () => {
  render(<SearchScreen />, { wrapper: Providers });
  const input = screen.getByLabelText('Search');

  fireEvent.changeText(input, 'n');
  fireEvent.changeText(input, 'no');
  fireEvent.changeText(input, 'noo');
  fireEvent.changeText(input, 'nood');
  // Before the debounce elapses, nothing fired.
  expect(mock.history.get.length).toBe(0);

  await act(async () => {
    jest.advanceTimersByTime(300);
  });
  await waitFor(() => expect(mock.history.get.length).toBe(1));
  expect(mock.history.get[0].params.q).toBe('nood');
});

it('renders Places and Tags sections and navigates on tap', async () => {
  render(<SearchScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Search'), 'nood');
  await act(async () => {
    jest.advanceTimersByTime(300);
  });

  expect(await screen.findByText('Places')).toBeOnTheScreen();
  expect(screen.getByText('Tags')).toBeOnTheScreen();

  fireEvent.press(screen.getByLabelText('Lanzhou Noodle House'));
  expect(mockRouter.push).toHaveBeenCalledWith({
    pathname: '/place/[slug]',
    params: { slug: 'lanzhou-noodle-house-6stwbu' },
  });

  fireEvent.press(screen.getByText('Noodles'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/tag/[slug]', params: { slug: 'noodles' } });
});

it('does not query for fewer than 2 characters', async () => {
  render(<SearchScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Search'), 'n');
  await act(async () => {
    jest.advanceTimersByTime(300);
  });
  expect(mock.history.get.length).toBe(0);
  expect(screen.getByText(/at least 2 characters/)).toBeOnTheScreen();
});

it('reverts to the hint immediately when the box is cleared (no stale results)', async () => {
  render(<SearchScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Search'), 'nood');
  await act(async () => {
    jest.advanceTimersByTime(300);
  });
  expect(await screen.findByText('Places')).toBeOnTheScreen();

  // Clear — the hint must show at once (driven by the immediate value), not
  // wait 300ms for the debounce to catch up.
  fireEvent.press(screen.getByLabelText('Clear'));
  expect(screen.getByText(/at least 2 characters/)).toBeOnTheScreen();
  expect(screen.queryByText('Places')).toBeNull();
});

it('shows an empty state when nothing matches', async () => {
  mock.onGet('/search').reply(200, { data: { places: [], tags: [], influencers: [] }, meta: { query: 'zzz', took_ms: 1 } });

  render(<SearchScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Search'), 'zzz');
  await act(async () => {
    jest.advanceTimersByTime(300);
  });
  expect(await screen.findByText(/No results for/)).toBeOnTheScreen();
});
