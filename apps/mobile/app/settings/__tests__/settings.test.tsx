import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import type { ReactNode } from 'react';

import SettingsScreen from '../index';
import { DEFAULT_LOCALE, useSettingsStore } from '@/stores/settings';
import { useSessionStore } from '@/stores/session';

// Settings grew a server-backed section in T-039 (the analysis-model picker),
// so the screen now needs a QueryClient. These tests still cover only the
// DEVICE settings — language and currency — which stay local to the store.
let qc: QueryClient;
function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  // Undo the global test override so we can prove the Spanish default.
  useSettingsStore.setState({ locale: DEFAULT_LOCALE });
  // A guest keeps the model picker (and its fetch) out of these cases.
  useSessionStore.setState({ user: null, status: 'guest' });
  (SecureStore.setItemAsync as jest.Mock).mockClear();
});

it('renders in Spanish by default', () => {
  render(<SettingsScreen />, { wrapper: Providers });
  expect(screen.getByText('Ajustes')).toBeOnTheScreen();
  expect(screen.getByText('Idioma')).toBeOnTheScreen();
});

it('flips to English, persists the choice, and re-renders live', () => {
  render(<SettingsScreen />, { wrapper: Providers });
  // Español is initially selected; tap English.
  fireEvent.press(screen.getByLabelText('English'));

  expect(useSettingsStore.getState().locale).toBe('en');
  expect(SecureStore.setItemAsync).toHaveBeenCalledWith('app_locale', 'en');
  // The header re-renders in English (same component, live locale switch).
  expect(screen.getByText('Settings')).toBeOnTheScreen();
});
