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
  // Asserted as an INVARIANT over every writer, not as a property of one setter.
  // The first version of these tests drove `setLocale` only — so when `hydrate()`
  // wrote the same field by another path and skipped the invalidation, every test
  // still passed. The bug was found in review. What follows is the shape that
  // would have caught it: enumerate the ways the locale can change, and require
  // the consequence from all of them.
  const writers: [string, (next: 'es' | 'en') => Promise<void> | void][] = [
    ['setLocale (the user picks a language in Settings)', (next) =>
      useSettingsStore.getState().setLocale(next)],
    ['hydrate (a cold start restores the saved language)', async (next) => {
      (SecureStore.getItemAsync as jest.Mock).mockImplementation(async (key: string) =>
        key === 'app_locale' ? next : null,
      );
      await useSettingsStore.getState().hydrate();
    }],
  ];

  it.each(writers)('invalidates the places tree via %s', async (_name, change) => {
    const spy = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    useSettingsStore.setState({ locale: 'es' });

    await change('en');

    expect(useSettingsStore.getState().locale).toBe('en');
    expect(spy).toHaveBeenCalledWith({ queryKey: ['places'] });
    spy.mockRestore();
  });

  it.each(writers)('does NOT invalidate via %s when the locale is unchanged', async (_name, change) => {
    // `hydrate()` runs on every cold start, so invalidating unconditionally would
    // discard the offline cache each launch — the exact cost that made bumping
    // the persist buster the wrong fix for this.
    const spy = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    useSettingsStore.setState({ locale: 'es' });

    await change('es');

    expect(spy).not.toHaveBeenCalled();
    spy.mockRestore();
  });

  it('has exactly the writers this suite enumerates', () => {
    // The guard against the NEXT one. A new way to change the locale must be
    // added to `writers` above — and if it does not route through applyLocale,
    // the cases there go red. Without this, a third writer is invisible again.
    const store = useSettingsStore.getState();
    const mutators = Object.entries(store)
      .filter(([key, value]) => typeof value === 'function' && key !== 'setCurrency')
      .map(([key]) => key)
      .sort();

    expect(mutators).toEqual(['hydrate', 'setLocale']);
  });
});
