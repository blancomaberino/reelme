import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import PrivacyScreen from '../privacy';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';

/**
 * The rollback path: `gdprSelfService` forced OFF.
 *
 * This is not dead code kept for sentiment. It is what a build looks like if
 * `DELETE /me` has to be pulled — and the requirement then is that the screen
 * degrades to "not available yet" rather than to two buttons that 404. A
 * disabled control that never says why is the failure this whole screen was
 * written to avoid.
 */
jest.mock('@/lib/feature-flags', () => ({ featureFlags: { gdprSelfService: false } }));

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
});

afterEach(() => {
  mock.restore();
});

it('says why the actions are off, once, above both cards', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  expect(screen.getByTestId('privacy-pending')).toBeOnTheScreen();
  // The explanation of both rights still ships — the point of the screen is
  // that the app admits what it holds, whether or not the buttons work.
  expect(screen.getByText('Get a copy of my data')).toBeOnTheScreen();
});

it('sends nothing when the disabled actions are pressed', () => {
  render(<PrivacyScreen />, { wrapper: Providers });

  const exportBtn = screen.getByTestId('privacy-export-action');
  const deleteBtn = screen.getByTestId('privacy-delete-action');
  expect(exportBtn.props.accessibilityState.disabled).toBe(true);
  expect(deleteBtn.props.accessibilityState.disabled).toBe(true);

  // A disabled Pressable still receives a synthetic press in RNTL, which is
  // precisely the regression worth pinning: no request goes out, no confirm
  // sheet opens, and the session survives a tap on "delete my account".
  fireEvent.press(exportBtn);
  fireEvent.press(deleteBtn);

  expect(mock.history.post).toHaveLength(0);
  expect(mock.history.delete).toHaveLength(0);
  expect(screen.queryByTestId('privacy-delete-confirm-input')).toBeNull();
  expect(useSessionStore.getState().status).toBe('authed');
});
