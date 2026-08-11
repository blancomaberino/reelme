import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import { type ReactNode, useState } from 'react';

import { api } from '@/api/client';
import type { Country } from '@/api/countries';
import { CountryPicker } from '@/components/country-picker';

let mock: AxiosMockAdapter;
let qc: QueryClient;

const CATALOG: Country[] = [
  { code: 'DE', name: 'Alemania' },
  { code: 'AD', name: 'Andorra' },
  { code: 'AR', name: 'Argentina' },
  { code: 'ES', name: 'España' },
  { code: 'TR', name: 'Türkiye' },
  { code: 'UY', name: 'Uruguay' },
];

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** Drives the picker with real state so a selection is observable. */
function Harness({ initial = null, onPick }: { initial?: Country | null; onPick?: (c: Country | null) => void }) {
  const [value, setValue] = useState<Country | null>(initial);
  const [open, setOpen] = useState(true);
  return (
    <CountryPicker
      visible={open}
      onClose={() => setOpen(false)}
      value={value?.code ?? null}
      onSelect={(c) => {
        setValue(c);
        onPick?.(c);
      }}
    />
  );
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/countries').reply(200, { data: CATALOG });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('lists the localized catalog it fetched — the app ships no country data', async () => {
  render(<Harness />, { wrapper: Providers });

  expect(await screen.findByText('Uruguay')).toBeOnTheScreen();
  expect(screen.getByText('Türkiye')).toBeOnTheScreen();
  // Names come from the server; nothing here is hardcoded, which is why this
  // asserts the SPANISH name for Germany from an English-free fixture.
  expect(screen.getByText('Alemania')).toBeOnTheScreen();
});

it('searches accent-insensitively over the localized name', async () => {
  render(<Harness />, { wrapper: Providers });
  await screen.findByText('Uruguay');

  fireEvent.changeText(screen.getByTestId('country-search'), 'espana');

  expect(await screen.findByText('España')).toBeOnTheScreen();
  expect(screen.queryByText('Uruguay')).toBeNull();
});

it('also matches the two-letter code, for people who know it', async () => {
  render(<Harness />, { wrapper: Providers });
  await screen.findByText('Uruguay');

  fireEvent.changeText(screen.getByTestId('country-search'), 'tr');

  expect(await screen.findByText('Türkiye')).toBeOnTheScreen();
  expect(screen.queryByText('Argentina')).toBeNull();
});

it('ranks a prefix match above a mid-word one', async () => {
  render(<Harness />, { wrapper: Providers });
  await screen.findByText('Uruguay');

  // "an" starts Andorra and sits mid-word in Alemania and España. Alphabetical
  // order would put Alemania first, which is the wrong answer for someone who
  // has typed the beginning of the country they want.
  fireEvent.changeText(screen.getByTestId('country-search'), 'an');

  const rows = await screen.findAllByTestId(/^country-row-/);
  expect(rows[0].props.testID).toBe('country-row-AD');
  expect(rows.map((r) => r.props.testID)).toEqual(
    expect.arrayContaining(['country-row-DE', 'country-row-ES']),
  );
});

it('reports a chosen country to the caller by code AND name', async () => {
  const onPick = jest.fn();
  render(<Harness onPick={onPick} />, { wrapper: Providers });

  fireEvent.press(await screen.findByTestId('country-row-UY'));

  // The whole row, so the caller can render "Uruguay" without a second lookup.
  expect(onPick).toHaveBeenCalledWith({ code: 'UY', name: 'Uruguay' });
});

it('hides Remove when nothing is selected — there is nothing to undo', async () => {
  render(<Harness />, { wrapper: Providers });
  await screen.findByText('Uruguay');

  expect(screen.queryByLabelText('Remove')).toBeNull();
});

it('clears a selected country to null', async () => {
  const onPick = jest.fn();
  render(<Harness initial={{ code: 'ES', name: 'España' }} onPick={onPick} />, { wrapper: Providers });

  fireEvent.press(await screen.findByLabelText('Remove'));

  // null, not '' — "I'd rather not say" has to reach the API as a cleared field.
  expect(onPick).toHaveBeenCalledWith(null);
});

it('says so when nothing matches instead of showing an empty sheet', async () => {
  render(<Harness />, { wrapper: Providers });
  await screen.findByText('Uruguay');

  fireEvent.changeText(screen.getByTestId('country-search'), 'zzzz');

  expect(await screen.findByText('No countries for “zzzz”')).toBeOnTheScreen();
});

it('surfaces a failed catalog rather than rendering an empty list', async () => {
  mock.onGet('/countries').reply(500);

  render(<Harness />, { wrapper: Providers });

  await waitFor(() => expect(screen.getByText('Couldn’t load the country list.')).toBeOnTheScreen());
});
