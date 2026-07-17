import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { FilterBar } from '@/components/map/filter-bar';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/tags').reply(200, { data: [] });
  mock.onGet('/places/payment-cards').reply(200, { data: [] });
  useMapStore.getState().clearFilters();
});
afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('toggles a price-tier filter into the map store', async () => {
  render(<FilterBar />, { wrapper: Providers });

  // Price tier 2 renders as "$$" via the format helper.
  fireEvent.press(await screen.findByText('$$'));
  await waitFor(() => expect(useMapStore.getState().filters.price_range).toBe(2));
});

it('toggles a payment-card filter into the map store (T-079)', async () => {
  mock.onGet('/places/payment-cards').reply(200, {
    data: [
      { card: 'Santander', count: 5 },
      { card: 'Visa', count: 2 },
    ],
  });
  render(<FilterBar />, { wrapper: Providers });

  fireEvent.press(await screen.findByText('💳 Santander'));
  await waitFor(() => expect(useMapStore.getState().filters.card).toBe('Santander'));

  // Pressing the active card again clears it.
  fireEvent.press(screen.getByText('💳 Santander'));
  await waitFor(() => expect(useMapStore.getState().filters.card).toBeNull());
});

it('no longer renders mine/following scope chips (the map is always personal, T-071)', () => {
  // Even an authed viewer sees no scope chips — the home map is implicitly mine.
  useSessionStore.setState({ user: null, status: 'authed' });
  render(<FilterBar />, { wrapper: Providers });

  expect(screen.queryByText('Following')).toBeNull();
  expect(screen.queryByText('Mine')).toBeNull();
});
