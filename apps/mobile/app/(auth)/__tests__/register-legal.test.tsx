import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Linking } from 'react-native';

import RegisterScreen from '../register';
import { api } from '@/api/client';
import { useSettingsStore } from '@/stores/settings';

/**
 * The consent line under the register button (T-054, Apple 1.2).
 *
 * Apple expects a UGC app's users to agree to the terms — and the terms are
 * where the zero-tolerance clause lives, so a link that is present but dead
 * satisfies nothing. These press it.
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
  useSettingsStore.setState({ locale: 'en' });
  process.env.EXPO_PUBLIC_API_URL = 'https://api.reelmap.app';
  openURL = jest.spyOn(Linking, 'openURL').mockResolvedValue(true);
});

afterEach(() => {
  mock.restore();
  openURL.mockRestore();
});

it('tells the user what they are agreeing to, at the moment they agree', () => {
  render(<RegisterScreen />, { wrapper: Providers });

  expect(screen.getByText(/By creating an account you agree to our/)).toBeTruthy();
  expect(screen.getByTestId('register-terms-link')).toBeTruthy();
  expect(screen.getByTestId('register-privacy-link')).toBeTruthy();
});

it('opens the terms from the consent line', () => {
  render(<RegisterScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByTestId('register-terms-link'));

  expect(openURL).toHaveBeenCalledWith('https://api.reelmap.app/terms/en');
});

it('opens the privacy policy from the consent line', () => {
  render(<RegisterScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByTestId('register-privacy-link'));

  expect(openURL).toHaveBeenCalledWith('https://api.reelmap.app/privacy/en');
});

it('follows the app language', () => {
  useSettingsStore.setState({ locale: 'es' });

  render(<RegisterScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByTestId('register-terms-link'));

  expect(openURL).toHaveBeenCalledWith('https://api.reelmap.app/terms/es');
});
