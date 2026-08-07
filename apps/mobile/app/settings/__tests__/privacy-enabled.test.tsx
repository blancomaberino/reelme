import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import PrivacyScreen from '../privacy';
import { api } from '@/api/client';
import { setToken } from '@/api/token';
import { registerForPush, unregisterPush } from '@/notifications/push';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

/**
 * Both data rights, working (T-050).
 *
 * The deletion half is a typed confirmation rather than a destructive alert,
 * and most of what follows is about that gate: an account deletion that a
 * mis-tap can reach is the one irreversible mistake this app can hand someone.
 */
jest.mock('@/lib/feature-flags', () => ({ featureFlags: { gdprSelfService: true } }));

// The delete path unregisters this device's push token first (an authed call
// that has to happen while the account still exists). Its own coverage lives in
// the push tests; here it only needs to not reach the network.
jest.mock('@/notifications/push', () => ({
  unregisterPush: jest.fn().mockResolvedValue(undefined),
  registerForPush: jest.fn().mockResolvedValue(undefined),
}));

let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** Open the sheet and spell the word out, as a user has to. */
function typeConfirmation(word = 'DELETE') {
  fireEvent.press(screen.getByTestId('privacy-delete-action'));
  fireEvent.changeText(screen.getByTestId('privacy-delete-confirm-input'), word);
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

it('tells a signed-out visitor why the actions are off, rather than just greying them', () => {
  // Reachable by deep link even though the settings row is authed-only. Without
  // this branch a guest gets two dead buttons and no explanation — the exact
  // thing this screen exists to avoid.
  useSessionStore.setState({ user: null, status: 'guest' });

  render(<PrivacyScreen />, { wrapper: Providers });

  expect(screen.getByTestId('privacy-signedOut')).toBeOnTheScreen();
  expect(screen.getByText('Sign in to use these')).toBeOnTheScreen();
  expect(screen.getByTestId('privacy-delete-action').props.accessibilityState.disabled).toBe(true);
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

it('opens a confirmation instead of deleting on the first press', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByTestId('privacy-delete-action'));

  expect(screen.getByTestId('privacy-delete-confirm-input')).toBeOnTheScreen();
  expect(mock.history.delete).toHaveLength(0);
  expect(useSessionStore.getState().status).toBe('authed');
});

it('keeps the confirm button dead until the word is spelled out', () => {
  render(<PrivacyScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('privacy-delete-action'));

  const confirm = screen.getByTestId('privacy-delete-confirm');
  expect(confirm.props.accessibilityState.disabled).toBe(true);

  // Half-typed is still not a decision.
  fireEvent.changeText(screen.getByTestId('privacy-delete-confirm-input'), 'DEL');
  expect(confirm.props.accessibilityState.disabled).toBe(true);

  // And a disabled press must send nothing — RNTL delivers it either way.
  fireEvent.press(confirm);
  expect(mock.history.delete).toHaveLength(0);

  fireEvent.changeText(screen.getByTestId('privacy-delete-confirm-input'), 'DELETE');
  expect(screen.getByTestId('privacy-delete-confirm').props.accessibilityState.disabled).toBe(false);
});

it('accepts the word in any case, with stray spaces', () => {
  // The gate is about deliberate intent, not typing accuracy. An on-screen
  // keyboard that auto-capitalises, or a trailing space from a suggestion bar,
  // must not fail someone for something they did not do.
  render(<PrivacyScreen />, { wrapper: Providers });
  typeConfirmation('  delete ');

  expect(screen.getByTestId('privacy-delete-confirm').props.accessibilityState.disabled).toBe(false);
});

it('deletes the account, wipes the device session, and leaves for welcome', async () => {
  mock.onDelete('/me').reply(200, { data: { status: 'scheduled' }, meta: {} });

  render(<PrivacyScreen />, { wrapper: Providers });
  typeConfirmation();
  fireEvent.press(screen.getByTestId('privacy-delete-confirm'));

  await waitFor(() => expect(mock.history.delete).toHaveLength(1));
  expect(mock.history.delete[0].url).toBe('/me');
  await waitFor(() => expect(useSessionStore.getState().status).toBe('guest'));
  expect(mockRouter.replace).toHaveBeenCalledWith('/(auth)/welcome');
});

it('abandons cleanly and forgets what was typed', () => {
  render(<PrivacyScreen />, { wrapper: Providers });
  typeConfirmation();

  fireEvent.press(screen.getByTestId('privacy-delete-cancel'));
  // Re-opening must start from a dead button. A sheet that remembered the word
  // would leave a live "delete everything" one tap away on the next visit.
  fireEvent.press(screen.getByTestId('privacy-delete-action'));

  expect(screen.getByTestId('privacy-delete-confirm').props.accessibilityState.disabled).toBe(true);
  expect(mock.history.delete).toHaveLength(0);
});

it('keeps the session when the server refuses the delete', async () => {
  // The failure that matters: signing someone out of an account that still
  // exists, while telling them it is gone, is worse than the error itself.
  mock.onDelete('/me').reply(500);
  jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);

  render(<PrivacyScreen />, { wrapper: Providers });
  typeConfirmation();
  fireEvent.press(screen.getByTestId('privacy-delete-confirm'));

  await waitFor(() => expect(mock.history.delete).toHaveLength(1));
  await waitFor(() =>
    expect(Alert.alert).toHaveBeenCalledWith('Couldn’t delete your account', expect.any(String)),
  );
  expect(useSessionStore.getState().status).toBe('authed');
  expect(mockRouter.replace).not.toHaveBeenCalled();

  // "Intact" has to include the push token: it is unregistered BEFORE the
  // delete (it must go while the account still exists), so a failure that
  // stopped here would leave a signed-in user silently push-deaf.
  expect(unregisterPush).toHaveBeenCalled();
  await waitFor(() => expect(registerForPush).toHaveBeenCalled());
});
