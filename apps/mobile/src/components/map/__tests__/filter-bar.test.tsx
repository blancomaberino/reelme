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
  useMapStore.getState().clearFilters();
});
afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('shows Following/Mine scope chips to an authed viewer and toggles the scope', async () => {
  useSessionStore.setState({ user: null, status: 'authed' });

  render(<FilterBar />, { wrapper: Providers });

  const following = await screen.findByText('Following');
  fireEvent.press(following);
  await waitFor(() => expect(useMapStore.getState().filters.filter).toBe('following'));

  fireEvent.press(screen.getByText('Mine'));
  await waitFor(() => expect(useMapStore.getState().filters.filter).toBe('mine'));
});

it('hides the scope chips from a guest', () => {
  useSessionStore.setState({ user: null, status: 'guest' });

  render(<FilterBar />, { wrapper: Providers });

  expect(screen.queryByText('Following')).toBeNull();
  expect(screen.queryByText('Mine')).toBeNull();
});
