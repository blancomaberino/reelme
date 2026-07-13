import { fireEvent, render, screen } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';

import SettingsScreen from '../index';
import { DEFAULT_LOCALE, useSettingsStore } from '@/stores/settings';

beforeEach(() => {
  // Undo the global test override so we can prove the Spanish default.
  useSettingsStore.setState({ locale: DEFAULT_LOCALE });
  (SecureStore.setItemAsync as jest.Mock).mockClear();
});

it('renders in Spanish by default', () => {
  render(<SettingsScreen />);
  expect(screen.getByText('Ajustes')).toBeOnTheScreen();
  expect(screen.getByText('Idioma')).toBeOnTheScreen();
});

it('flips to English, persists the choice, and re-renders live', () => {
  render(<SettingsScreen />);
  // Español is initially selected; tap English.
  fireEvent.press(screen.getByLabelText('English'));

  expect(useSettingsStore.getState().locale).toBe('en');
  expect(SecureStore.setItemAsync).toHaveBeenCalledWith('app_locale', 'en');
  // The header re-renders in English (same component, live locale switch).
  expect(screen.getByText('Settings')).toBeOnTheScreen();
});
