import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import { type ReactNode, useState } from 'react';

import { api } from '@/api/client';
import { queryKeys, type MyPlacesFilters as Filters } from '@/api/keys';
import { MyPlacesFilters } from '@/components/place/my-places-filters';
import { useSettingsStore } from '@/stores/settings';

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
  // The localized country catalog (T-110) — what turns "UY" into "Uruguay".
  mock.onGet('/countries').reply(200, {
    data: [
      { code: 'AR', name: 'Argentina' },
      { code: 'UY', name: 'Uruguay' },
    ],
  });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('picks a country in the sheet and shows it as a removable chip, named not coded', async () => {
  render(<Harness />, { wrapper: Providers });

  // Countries are hidden until the sheet opens.
  expect(screen.queryByText('Uruguay')).toBeNull();
  fireEvent.press(screen.getByLabelText('Filters'));

  // The option reads "Uruguay", never the stored "UY" (T-110) — the facet value
  // is still the code, which is what the removal assertion below depends on.
  fireEvent.press(await screen.findByLabelText('Uruguay'));
  expect(screen.queryByLabelText('UY')).toBeNull();

  // Applied country now shows as a chip; removing it clears the facet.
  const chip = await screen.findByLabelText('Remove Uruguay filter');
  fireEvent.press(chip);
  await waitFor(() => expect(screen.queryByLabelText('Remove Uruguay filter')).toBeNull());
});

it('falls back to the raw code when the country catalog is unavailable', async () => {
  // A chip that renders nothing (or crashes) because a secondary request failed
  // is worse than one that reads "UY" — the filter still has to be removable.
  mock.onGet('/countries').reply(500);

  render(<Harness initial={{ sort: 'recent', country: 'UY' }} />, { wrapper: Providers });

  // Assert AFTER the query settles. At first paint the chip reads "UY" whatever
  // the server said, so a `find` alone passes even on a 200 — it would be
  // testing the loading state and calling it the failure state.
  await waitFor(() =>
    expect(qc.getQueryState(queryKeys.countries(useSettingsStore.getState().locale))?.status).toBe('error'),
  );
  expect(screen.getByLabelText('Remove UY filter')).toBeTruthy();
});

it('does not pull the 249-row catalog when there is no country to name', async () => {
  render(<Harness countries={[]} initial={{ sort: 'recent' }} />, { wrapper: Providers });
  await screen.findByLabelText('Filters');

  expect(mock.history.get.some((r) => r.url === '/countries')).toBe(false);
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

  // Two facets are pre-applied → both show as chips. `find`, not `get`: the
  // chip starts as "AR" and becomes "Argentina" when the catalog lands.
  expect(await screen.findByLabelText('Remove Argentina filter')).toBeTruthy();
  fireEvent.press(screen.getByLabelText('Filters'));
  fireEvent.press(await screen.findByLabelText('Clear'));

  await waitFor(() => {
    expect(screen.queryByLabelText('Remove Argentina filter')).toBeNull();
    expect(screen.queryByLabelText(/Remove .* filter/)).toBeNull();
  });
});
