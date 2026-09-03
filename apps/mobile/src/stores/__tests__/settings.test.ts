import * as SecureStore from 'expo-secure-store';

import { LOCALIZED_KEY_PREFIXES } from '@/api/keys';
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
  //
  // Two rounds of this were wrong in the same way. First the rule lived in
  // `setLocale`, and `hydrate()` wrote the field directly and skipped it — found
  // in review, because the test drove the setter it was written beside. Then it
  // lived in BOTH writers, and a bare `setState({ locale })` from anywhere would
  // still skip it — found in review again, because the test enumerated the
  // store's own methods and a direct setState is not one of them.
  //
  // It is now a SUBSCRIPTION: the rule is where the state changes, not where a
  // caller happens to be. That is what makes the last case below passable at all,
  // and it is why these are written as "every way the locale can change" rather
  // than as a list of functions.
  // The spy is on the MODULE SINGLETON, which is deliberate and is what the app
  // uses: `_layout.tsx` wraps everything in `PersistQueryClientProvider
  // client={queryClient}` from '@/api/query-client'. Screen tests build their own
  // client, so this could not be asserted there without lying about which object
  // production talks to. What is left untested is whether an invalidation makes a
  // mounted screen refetch — that is react-query's behaviour, not ours.
  const writers: [string, (next: 'es' | 'en') => Promise<void> | void][] = [
    ['setLocale (the user picks a language in Settings)', (next) =>
      useSettingsStore.getState().setLocale(next)],
    ['hydrate (a cold start restores the saved language)', async (next) => {
      (SecureStore.getItemAsync as jest.Mock).mockImplementation(async (key: string) =>
        key === 'app_locale' ? next : null,
      );
      await useSettingsStore.getState().hydrate();
    }],
    // Not a supported call site — deliberately here anyway. If a future writer
    // reaches the field by any route, this is the case that already covers it.
    ['a direct setState from outside the store', (next) =>
      useSettingsStore.setState({ locale: next })],
  ];

  it.each(writers)('invalidates the places tree via %s', async (_name, change) => {
    const spy = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    useSettingsStore.setState({ locale: 'es' });
    spy.mockClear();

    await change('en');

    expect(useSettingsStore.getState().locale).toBe('en');
    // EVERY localized prefix, not just places. The first version invalidated
    // `['places']` alone and left the tag catalog in the old language — a Spanish
    // filter sheet beside English hours, surviving cold starts because
    // `['me','places','tags']` is persisted for 24h. Asserting the whole list
    // means adding a localized endpoint without adding its key turns this red.
    for (const queryKey of LOCALIZED_KEY_PREFIXES) {
      expect(spy).toHaveBeenCalledWith({ queryKey });
    }
    expect(spy).toHaveBeenCalledTimes(LOCALIZED_KEY_PREFIXES.length);
    spy.mockRestore();
  });

  it.each(writers)('does NOT invalidate via %s when the locale is unchanged', async (_name, change) => {
    // `hydrate()` runs on every cold start, so invalidating unconditionally would
    // discard the offline cache each launch — the cost that made bumping the
    // persist buster the wrong fix for this.
    const spy = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
    useSettingsStore.setState({ locale: 'es' });
    spy.mockClear();

    await change('es');

    expect(spy).not.toHaveBeenCalled();
    spy.mockRestore();
  });

  it('leaves the currency alone — only the language re-asks', () => {
    // The subscription fires on EVERY state change, so it has to discriminate.
    // Without the locale comparison it would refetch on a currency toggle too,
    // which is a cache thrash with no cause.
    const spy = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();

    useSettingsStore.getState().setCurrency('€');

    expect(spy).not.toHaveBeenCalled();
    spy.mockRestore();
  });
});
