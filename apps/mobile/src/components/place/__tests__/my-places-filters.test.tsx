import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import { type ReactNode, useState } from 'react';

import { api } from '@/api/client';
import type { MyPlacesFilters as Filters } from '@/api/keys';
import { MyPlacesFilters } from '@/components/place/my-places-filters';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** Drives MyPlacesFilters with real state so chips reflect the applied patch. */
function Harness({
  countries = COUNTRIES,
  types = TYPES,
  initial = { sort: 'recent' },
}: {
  countries?: string[];
  types?: string[];
  initial?: Filters;
}) {
  const [filters, setFilters] = useState<Filters>(initial);
  return (
    <MyPlacesFilters
      countries={countries}
      types={types}
      filters={filters}
      onChange={(patch) => setFilters((f) => ({ ...f, ...patch }))}
    />
  );
}

// The country/type options now come from the full-collection facet endpoint,
// passed straight in as props (T-088) — no longer derived from loaded rows.
const COUNTRIES = ['AR', 'UY'];
const TYPES = ['bakery', 'american'];

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/tags').reply(200, { data: [] });
  mock.onGet('/me/places/tags').reply(200, { data: [] });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('picks a country in the sheet and shows it as a removable chip', async () => {
  render(<Harness />, { wrapper: Providers });

  // Country codes are hidden until the sheet opens.
  expect(screen.queryByText('UY')).toBeNull();
  fireEvent.press(screen.getByLabelText('Filters'));
  fireEvent.press(await screen.findByLabelText('UY'));

  // Applied country now shows as a chip; removing it clears the facet.
  const chip = await screen.findByLabelText('Remove UY filter');
  fireEvent.press(chip);
  await waitFor(() => expect(screen.queryByLabelText('Remove UY filter')).toBeNull());
});

it('toggles the sort order between recent and popular', async () => {
  render(<Harness />, { wrapper: Providers });
  fireEvent.press(screen.getByLabelText('Filters'));

  // "Recent" starts selected; picking "Popular" flips the accessibility state.
  const popular = await screen.findByLabelText('Popular');
  expect(popular.props.accessibilityState?.selected).toBe(false);
  fireEvent.press(popular);
  await waitFor(() => expect(screen.getByLabelText('Popular').props.accessibilityState?.selected).toBe(true));
});

it('clear removes every applied facet at once', async () => {
  render(<Harness initial={{ sort: 'recent', country: 'AR', type: 'bakery' }} />, {
    wrapper: Providers,
  });

  // Two facets are pre-applied → both show as chips.
  expect(screen.getByLabelText('Remove AR filter')).toBeTruthy();
  fireEvent.press(screen.getByLabelText('Filters'));
  fireEvent.press(await screen.findByLabelText('Clear'));

  await waitFor(() => {
    expect(screen.queryByLabelText('Remove AR filter')).toBeNull();
    expect(screen.queryByLabelText(/Remove .* filter/)).toBeNull();
  });
});
