import * as SecureStore from 'expo-secure-store';

import { queryClient } from '@/api/query-client';

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

describe('locale change re-asks for what the server localized (T-168)', () => {
  it('invalidates the places tree when the language actually changes', () => {
    // Changing the language here only changes `Accept-Language` on the NEXT
    // request. The cached place was rendered by the API for the OLD one, so
    // without this a Spanish UI keeps showing an English week — which is the bug
    // T-168 closed, arriving by the cache instead of by the server.
    const spy = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    useSettingsStore.setState({ locale: 'es' });

    useSettingsStore.getState().setLocale('en');

    expect(spy).toHaveBeenCalledWith({ queryKey: ['places'] });
    spy.mockRestore();
  });

  it('does NOT invalidate when the locale is set to what it already was', () => {
    // `setLocale` runs on every hydrate, so invalidating unconditionally would
    // discard the offline cache on each cold start — the exact cost that made
    // bumping the persist buster the wrong fix.
    const spy = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    useSettingsStore.setState({ locale: 'es' });

    useSettingsStore.getState().setLocale('es');

    expect(spy).not.toHaveBeenCalled();
    spy.mockRestore();
  });
});
