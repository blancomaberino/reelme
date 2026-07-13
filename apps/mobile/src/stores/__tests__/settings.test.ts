import * as SecureStore from 'expo-secure-store';

import { DEFAULT_LOCALE, useSettingsStore } from '../settings';

beforeEach(() => {
  useSettingsStore.setState({ locale: DEFAULT_LOCALE });
  (SecureStore.setItemAsync as jest.Mock).mockClear();
});

it('defaults to Spanish', () => {
  expect(DEFAULT_LOCALE).toBe('es');
  expect(useSettingsStore.getState().locale).toBe('es');
});

it('setLocale updates the store and persists to SecureStore', () => {
  useSettingsStore.getState().setLocale('en');
  expect(useSettingsStore.getState().locale).toBe('en');
  expect(SecureStore.setItemAsync).toHaveBeenCalledWith('app_locale', 'en');
});

it('hydrate applies a saved locale', async () => {
  await SecureStore.setItemAsync('app_locale', 'en');
  await useSettingsStore.getState().hydrate();
  expect(useSettingsStore.getState().locale).toBe('en');
});

it('hydrate keeps the Spanish default when nothing is saved', async () => {
  (SecureStore.getItemAsync as jest.Mock).mockResolvedValueOnce(null);
  await useSettingsStore.getState().hydrate();
  expect(useSettingsStore.getState().locale).toBe('es');
});
