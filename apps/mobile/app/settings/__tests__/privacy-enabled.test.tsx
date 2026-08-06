import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import PrivacyScreen from '../privacy';
import { api } from '@/api/client';
import { setToken } from '@/api/token';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

/**
 * The SAME screen with the flag on — i.e. the app T-050 ships by flipping one
 * boolean. This file is the whole reason the flag is worth having: the M5 path
 * is written, wired and covered now, next to the copy it belongs to, instead of
 * being reconstructed from a task note months later.
 *
 * If these cases ever fail, "M5 is just a flip" has stopped being true.
 */
jest.mock('@/lib/feature-flags', () => ({ featureFlags: { gdprSelfService: true } }));

// The delete path unregisters this device's push token first (an authed call
// that has to happen while the account still exists). Its own coverage lives in
// the push tests; here it only needs to not reach the network.
jest.mock('@/notifications/push', () => ({ unregisterPush: jest.fn().mockResolvedValue(undefined) }));

let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** Take the destructive button in the confirm dialog. */
function confirmDestructive() {
  return jest.spyOn(Alert, 'alert').mockImplementation((_title, _msg, buttons) => {
    // Optional-chained because the failure path raises a SECOND, button-less
    // alert through this same spy — reaching into `buttons` unconditionally
    // would throw there and turn a real assertion into a crash.
    const destructive = (buttons as { style?: string; onPress?: () => void }[] | undefined)?.find(
      (b) => b.style === 'destructive',
    );
    destructive?.onPress?.();
  });
}

beforeEach(async () => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
  await setToken('tok_1');
  useSessionStore.setState({ user: null, status: 'authed' });
  mockRouter.replace.mockClear();
});

afterEach(() => {
  mock.restore();
  jest.restoreAllMocks();
});

it('hides the "not available yet" notice once the flag is on', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  expect(screen.queryByTestId('privacy-pending')).toBeNull();
  expect(screen.getByTestId('privacy-export-action').props.accessibilityState.disabled).toBe(false);
});

it('requests the export and then says where the copy is going', async () => {
  mock.onPost('/me/export').reply(202);

  render(<PrivacyScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('privacy-export-action'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  expect(mock.history.post[0].url).toBe('/me/export');
  // The button is replaced by the answer — pressing it twice would queue a
  // second archive for no reason, and "did that work?" is the question the
  // screen has to close.
  await screen.findByTestId('privacy-export-done');
  expect(screen.queryByTestId('privacy-export-action')).toBeNull();
});

it('reports a failed export instead of pretending it worked', async () => {
  mock.onPost('/me/export').reply(500);
  const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);

  render(<PrivacyScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('privacy-export-action'));

  await waitFor(() => expect(alert).toHaveBeenCalled());
  // The "your copy is on its way" panel must NOT appear — nothing is on its way.
  expect(screen.queryByTestId('privacy-export-done')).toBeNull();
  expect(screen.getByTestId('privacy-export-action')).toBeOnTheScreen();
});

it('never deletes without a confirmation', () => {
  const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);

  render(<PrivacyScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('privacy-delete-action'));

  expect(alert).toHaveBeenCalled();
  expect(mock.history.delete).toHaveLength(0);
  expect(useSessionStore.getState().status).toBe('authed');
});

it('deletes the account, wipes the device session, and leaves for welcome', async () => {
  mock.onDelete('/me').reply(204);
  confirmDestructive();

  render(<PrivacyScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('privacy-delete-action'));

  await waitFor(() => expect(mock.history.delete).toHaveLength(1));
  expect(mock.history.delete[0].url).toBe('/me');
  await waitFor(() => expect(useSessionStore.getState().status).toBe('guest'));
  expect(mockRouter.replace).toHaveBeenCalledWith('/(auth)/welcome');
});

it('keeps the session when the server refuses the delete', async () => {
  // The failure that matters: signing someone out of an account that still
  // exists, while telling them it is gone, is worse than the error itself.
  mock.onDelete('/me').reply(500);
  confirmDestructive();

  render(<PrivacyScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('privacy-delete-action'));

  await waitFor(() => expect(mock.history.delete).toHaveLength(1));
  await waitFor(() => expect(Alert.alert).toHaveBeenCalledWith('Couldn’t delete your account', expect.any(String)));
  expect(useSessionStore.getState().status).toBe('authed');
  expect(mockRouter.replace).not.toHaveBeenCalled();
});
