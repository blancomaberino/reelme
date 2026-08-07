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
 * The screen as it actually ships (T-039 screen, T-050 endpoints).
 *
 * No `jest.mock` of the flag module here on purpose — these cases read the real
 * compiled default, so the assertion below is a genuine build guard rather than
 * a restatement of a mock. `privacy-enabled` and `privacy-disabled` cover the
 * two branches with the flag forced either way.
 */

let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
  mock.onAny().reply(404);
  useSessionStore.setState({ user: null, status: 'authed' });
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
});

it('ships with GDPR self-service ON', () => {
  // Apple requires in-app account deletion (Guideline 5.1.1(v)), so a build
  // that compiled this OFF is a build that fails store review — and it would
  // fail it silently, months after whoever flipped it had moved on.
  expect(featureFlags.gdprSelfService).toBe(true);
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

it('explains both rights, including what deletion does not erase', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  expect(screen.getByText('Get a copy of my data')).toBeOnTheScreen();
  expect(screen.getByText('Delete my account')).toBeOnTheScreen();
  expect(screen.getByText(/one file and email you a link/)).toBeOnTheScreen();
  expect(screen.getByText(/credited to nobody/)).toBeOnTheScreen();
  // Retention law keeps payment records out of the purge (T-050), so deletion
  // copy that promised "everything, for good" would be a false statement to the
  // exact users who have those rows — influencers and restaurant owners.
  expect(screen.getByText(/Payment and redemption records are kept/)).toBeOnTheScreen();
  // Nothing is pending any more, so the "not available yet" notice must be gone.
  expect(screen.queryByTestId('privacy-pending')).toBeNull();
});

it('offers both actions to a signed-in user', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  expect(screen.getByTestId('privacy-export-action').props.accessibilityState.disabled).toBe(false);
  expect(screen.getByTestId('privacy-delete-action').props.accessibilityState.disabled).toBe(false);
});
