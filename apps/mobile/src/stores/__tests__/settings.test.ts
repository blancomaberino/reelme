import * as SecureStore from 'expo-secure-store';

import { DEFAULT_CURRENCY, DEFAULT_LOCALE, useSettingsStore } from '../settings';

beforeEach(() => {
  useSettingsStore.setState({ locale: DEFAULT_LOCALE, currency: DEFAULT_CURRENCY });
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
  (SecureStore.getItemAsync as jest.Mock).mockResolvedValue(null);
  await useSettingsStore.getState().hydrate();
  expect(useSettingsStore.getState().locale).toBe('es');
});

it('defaults currency to $ and setCurrency persists', () => {
  expect(DEFAULT_CURRENCY).toBe('$');
  expect(useSettingsStore.getState().currency).toBe('$');
  useSettingsStore.getState().setCurrency('€');
  expect(useSettingsStore.getState().currency).toBe('€');
  expect(SecureStore.setItemAsync).toHaveBeenCalledWith('app_currency', '€');
});

it('hydrate applies a saved currency', async () => {
  (SecureStore.getItemAsync as jest.Mock).mockImplementation(async (k: string) =>
    k === 'app_currency' ? '£' : null,
  );
  await useSettingsStore.getState().hydrate();
  expect(useSettingsStore.getState().currency).toBe('£');
});
