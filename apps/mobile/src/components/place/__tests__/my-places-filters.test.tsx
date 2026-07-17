import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import { type ReactNode, useState } from 'react';

import { api } from '@/api/client';
import type { MyPlacesFilters as Filters } from '@/api/keys';
import type { PlaceSummary } from '@/api/places';
import { MyPlacesFilters } from '@/components/place/my-places-filters';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** A minimal my-places row; only country_code / category feed the facet chips. */
function place(over: Partial<PlaceSummary>): PlaceSummary {
  return {
    id: 'p1',
    name: 'Place',
    slug: 'place',
    status: 'active',
    lat: 0,
    lng: 0,
    category: null,
    price_range: null,
    city: null,
    country_code: 'AR',
    source_count: 0,
    rating: { google: { value: null, count: 0 } },
    distance_m: null,
    created_at: null,
    ...over,
  };
}

/** Drives MyPlacesFilters with real state so chips reflect the applied patch. */
function Harness({ places, initial = { sort: 'recent' } }: { places: PlaceSummary[]; initial?: Filters }) {
  const [filters, setFilters] = useState<Filters>(initial);
  return (
    <MyPlacesFilters places={places} filters={filters} onChange={(patch) => setFilters((f) => ({ ...f, ...patch }))} />
  );
}

const PLACES = [
  place({ id: 'a', country_code: 'AR', category: 'bakery' }),
  place({ id: 'b', country_code: 'UY', category: 'american' }),
];

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
  render(<Harness places={PLACES} />, { wrapper: Providers });

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
  render(<Harness places={PLACES} />, { wrapper: Providers });
  fireEvent.press(screen.getByLabelText('Filters'));

  // "Recent" starts selected; picking "Popular" flips the accessibility state.
  const popular = await screen.findByLabelText('Popular');
  expect(popular.props.accessibilityState?.selected).toBe(false);
  fireEvent.press(popular);
  await waitFor(() => expect(screen.getByLabelText('Popular').props.accessibilityState?.selected).toBe(true));
});

it('clear removes every applied facet at once', async () => {
  render(<Harness places={PLACES} initial={{ sort: 'recent', country: 'AR', type: 'bakery' }} />, {
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
