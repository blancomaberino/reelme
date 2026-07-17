import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
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
  mock.onGet('/me/places/tags').reply(200, { data: [] });
  mock.onGet('/places/payment-cards').reply(200, { data: [] });
  useMapStore.getState().clearFilters();
});
afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('keeps options behind the Filters button and toggles a price tier from the sheet', async () => {
  render(<FilterBar />, { wrapper: Providers });

  // The bar shows only the Filters button — no options until it is opened.
  expect(screen.queryByText('$$')).toBeNull();
  fireEvent.press(screen.getByLabelText('Filters'));

  // Price tier 2 renders as "$$" via the format helper.
  fireEvent.press(await screen.findByText('$$'));
  await waitFor(() => expect(useMapStore.getState().filters.price_range).toBe(2));
});

it('surfaces an applied filter as a removable chip without opening the sheet', async () => {
  useMapStore.getState().togglePrice(3); // pre-select "$$$"
  render(<FilterBar />, { wrapper: Providers });

  const chip = screen.getByLabelText('Remove $$$ filter');
  fireEvent.press(chip);
  await waitFor(() => expect(useMapStore.getState().filters.price_range).toBeNull());
});

it('toggles a payment-card filter from the sheet (T-079)', async () => {
  mock.onGet('/places/payment-cards').reply(200, {
    data: [
      { card: 'Santander', count: 5 },
      { card: 'Visa', count: 2 },
    ],
  });
  render(<FilterBar />, { wrapper: Providers });
  fireEvent.press(screen.getByLabelText('Filters'));

  fireEvent.press(await screen.findByText('💳 Santander'));
  await waitFor(() => expect(useMapStore.getState().filters.card).toBe('Santander'));

  // The selected card surfaces as an applied chip; removing it clears the card.
  fireEvent.press(await screen.findByLabelText('Remove 💳 Santander filter'));
  await waitFor(() => expect(useMapStore.getState().filters.card).toBeNull());
});

it('clear resets the facet filters but preserves an active list scope', async () => {
  useMapStore.getState().setList({ id: 'l1', name: 'Lisbon' });
  useMapStore.getState().togglePrice(2);
  render(<FilterBar />, { wrapper: Providers });
  fireEvent.press(screen.getByLabelText('Filters'));

  fireEvent.press(await screen.findByLabelText('Clear'));
  await waitFor(() => expect(useMapStore.getState().filters.price_range).toBeNull());
  // The saved-list scope (shown as the map banner) survives a facet clear.
  expect(useMapStore.getState().filters.list).toEqual({ id: 'l1', name: 'Lisbon' });
});
