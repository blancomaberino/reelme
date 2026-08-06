import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import PrivacyScreen from '../privacy';
import SettingsScreen from '../index';
import { api } from '@/api/client';
import { featureFlags } from '@/lib/feature-flags';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

/**
 * The state this app actually ships in M3: `gdprSelfService` OFF, because
 * `POST /me/export` and `DELETE /me` do not exist until T-050.
 *
 * No `jest.mock` of the flag module here on purpose — these cases read the real
 * compiled default, so if somebody flips it on without the endpoints, this file
 * fails rather than quietly starting to test a different app.
 */

let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
  // Anything the screen tries to send 404s exactly as the live API would.
  mock.onAny().reply(404);
  useSessionStore.setState({ user: null, status: 'authed' });
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
});

it('ships with GDPR self-service off (this is what T-050 flips)', () => {
  expect(featureFlags.gdprSelfService).toBe(false);
});

it('is reachable from the settings hub', () => {
  render(<SettingsScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByTestId('settings-privacy'));

  expect(mockRouter.push).toHaveBeenCalledWith('/settings/privacy');
});

it('does not offer the privacy row to a guest', () => {
  useSessionStore.setState({ user: null, status: 'guest' });

  render(<SettingsScreen />, { wrapper: Providers });

  expect(screen.queryByTestId('settings-privacy')).toBeNull();
});

it('explains both rights even though neither is actionable yet', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  // The explanation is the part that ships today, so it must be on screen —
  // not hidden behind the disabled controls.
  expect(screen.getByText('Get a copy of my data')).toBeOnTheScreen();
  expect(screen.getByText('Delete my account')).toBeOnTheScreen();
  expect(screen.getByText(/one file and email you a link/)).toBeOnTheScreen();
  expect(screen.getByText(/credited to nobody/)).toBeOnTheScreen();
  // Retention law keeps payment records out of the purge (T-050), so deletion
  // copy that promised "everything, for good" would be a false statement to the
  // exact users who have those rows — influencers and restaurant owners.
  expect(screen.getByText(/Payment and redemption records are kept/)).toBeOnTheScreen();
  // ...and says, once, why the buttons don't work.
  expect(screen.getByTestId('privacy-pending')).toBeOnTheScreen();
});

it('disables both actions and sends nothing when pressed', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  const exportBtn = screen.getByTestId('privacy-export-action');
  const deleteBtn = screen.getByTestId('privacy-delete-action');
  expect(exportBtn.props.accessibilityState.disabled).toBe(true);
  expect(deleteBtn.props.accessibilityState.disabled).toBe(true);

  // A disabled Pressable still receives a synthetic press in RNTL, which is
  // precisely the regression worth pinning: the request must not go out, and
  // the session must survive a tap on "delete my account".
  fireEvent.press(exportBtn);
  fireEvent.press(deleteBtn);

  expect(mock.history.post).toHaveLength(0);
  expect(mock.history.delete).toHaveLength(0);
  expect(useSessionStore.getState().status).toBe('authed');
});
