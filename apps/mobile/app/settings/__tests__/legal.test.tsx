import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Linking } from 'react-native';

import SettingsScreen from '../index';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';

/**
 * The legal rows in Settings (T-054, Apple 5.1.1 / 1.2).
 *
 * The assertion that matters most here is the guest one. Every other row on
 * this screen is behind `authed`, so the natural way to add these would have
 * put them there too — and a privacy policy only readable once you have an
 * account is exactly the finding App Review sends back.
 */

let qc: QueryClient;
let mock: AxiosMockAdapter;
let openURL: jest.SpyInstance;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
  mock.onAny().reply(404);
  useSessionStore.setState({ user: null, status: 'authed' });
  useSettingsStore.setState({ locale: 'es' });
  process.env.EXPO_PUBLIC_API_URL = 'https://api.reelmap.app';
  openURL = jest.spyOn(Linking, 'openURL').mockResolvedValue(true);
});

afterEach(() => {
  mock.restore();
  openURL.mockRestore();
});

it('offers both documents to a signed-out visitor', () => {
  useSessionStore.setState({ user: null, status: 'guest' });

  render(<SettingsScreen />, { wrapper: Providers });

  expect(screen.getByTestId('settings-legal-privacy')).toBeTruthy();
  expect(screen.getByTestId('settings-legal-terms')).toBeTruthy();
});

it('opens the privacy policy in the browser when pressed', () => {
  render(<SettingsScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByTestId('settings-legal-privacy'));

  expect(openURL).toHaveBeenCalledWith('https://api.reelmap.app/privacy/es');
});

it('opens the terms in the browser when pressed', () => {
  render(<SettingsScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByTestId('settings-legal-terms'));

  expect(openURL).toHaveBeenCalledWith('https://api.reelmap.app/terms/es');
});

it('follows the language chosen in the app, not the device', () => {
  // The row is one screen below the language toggle. Opening the Spanish
  // policy for someone who just switched the app to English is the kind of
  // thing that renders perfectly and is still wrong.
  useSettingsStore.setState({ locale: 'en' });

  render(<SettingsScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('settings-legal-privacy'));

  expect(openURL).toHaveBeenCalledWith('https://api.reelmap.app/privacy/en');
});

it('labels the rows in the language the app is running in', () => {
  useSettingsStore.setState({ locale: 'en' });
  render(<SettingsScreen />, { wrapper: Providers });
  expect(screen.getByText('Privacy policy')).toBeTruthy();

  screen.unmount();

  useSettingsStore.setState({ locale: 'es' });
  render(<SettingsScreen />, { wrapper: Providers });
  expect(screen.getByText('Política de privacidad')).toBeTruthy();
});

it('does nothing rather than opening a broken URL when the origin is unset', () => {
  delete process.env.EXPO_PUBLIC_API_URL;

  render(<SettingsScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('settings-legal-privacy'));

  expect(openURL).not.toHaveBeenCalled();
});
