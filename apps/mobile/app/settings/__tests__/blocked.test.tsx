import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import BlockedAccountsScreen from '../blocked';
import { api } from '@/api/client';

/**
 * Blocked accounts (T-054, IR-6 / Apple Guideline 1.2).
 *
 * This screen is the *undo* for blocking. A blocked profile is a 404 for the
 * person who blocked it — deliberately — so this list is the only route back to
 * it, and a bug here makes every block permanent by accident.
 */
let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

const noisy = { id: '7', username: 'noisy', name: 'Noisy Neighbour', avatar_url: null };

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
});

afterEach(() => {
  mock.restore();
  jest.restoreAllMocks();
});

it('lists the accounts this user has blocked', async () => {
  mock.onGet('/me/blocks').reply(200, { data: [noisy] });

  render(<BlockedAccountsScreen />, { wrapper: Providers });

  expect(await screen.findByText('@noisy')).toBeOnTheScreen();
  expect(screen.getByText('Noisy Neighbour')).toBeOnTheScreen();
});

it('says so plainly when nobody is blocked', async () => {
  mock.onGet('/me/blocks').reply(200, { data: [] });

  render(<BlockedAccountsScreen />, { wrapper: Providers });

  // An empty screen with no message reads as a screen that failed to load.
  expect(await screen.findByTestId('blocked-empty')).toBeOnTheScreen();
});

it('unblocks after a confirmation, and drops the row', async () => {
  mock.onGet('/me/blocks').replyOnce(200, { data: [noisy] });
  mock.onDelete('/me/blocks/noisy').reply(204);
  mock.onGet('/me/blocks').reply(200, { data: [] });

  // Take the confirm branch — `Alert.alert` renders nothing in jest, so without
  // this the destructive button is untestable and the test would only prove the
  // dialog was requested.
  jest.spyOn(Alert, 'alert').mockImplementation((_title, _body, buttons) => {
    buttons?.find((b) => b.style !== 'cancel')?.onPress?.();
  });

  render(<BlockedAccountsScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('Unblock'));

  await waitFor(() => expect(mock.history.delete).toHaveLength(1));
  // Refetched, not just locally spliced: the list has to agree with the server.
  await waitFor(() => expect(screen.queryByText('@noisy')).toBeNull());
});

it('does not unblock when the confirmation is dismissed', async () => {
  mock.onGet('/me/blocks').reply(200, { data: [noisy] });
  mock.onDelete('/me/blocks/noisy').reply(204);

  // Cancel. Unblocking is not destructive, but doing it because somebody
  // tapped past a dialog is still the app deciding on their behalf.
  jest.spyOn(Alert, 'alert').mockImplementation(() => {});

  render(<BlockedAccountsScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('Unblock'));

  await waitFor(() => expect(mock.history.delete).toHaveLength(0));
  expect(screen.getByText('@noisy')).toBeOnTheScreen();
});

it('shows an error rather than an empty list when the request fails', async () => {
  mock.onGet('/me/blocks').reply(500);

  render(<BlockedAccountsScreen />, { wrapper: Providers });

  // "You haven't blocked anyone" on a failed request is a lie that would make
  // somebody think their block had been lifted.
  expect(await screen.findByTestId('blocked-error')).toBeOnTheScreen();
  expect(screen.queryByTestId('blocked-empty')).toBeNull();
});
