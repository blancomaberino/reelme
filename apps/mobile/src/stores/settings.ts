import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

import { queryClient } from '@/api/query-client';

export type Locale = 'es' | 'en';
export type Currency = '$' | '€' | '£';

// Spanish is the default ("main app language must be Spanish"); the user can
// override it in Settings and the choice persists in SecureStore (already a
// dependency for the auth token — no extra native module / AsyncStorage rebuild).
const LOCALE_KEY = 'app_locale';
const CURRENCY_KEY = 'app_currency';
export const DEFAULT_LOCALE: Locale = 'es';
// The price tier is an abstract affordability glyph; "$" reads right for the
// Spanish/LatAm-first audience (was "€" from the European MERCADO flourish).
export const DEFAULT_CURRENCY: Currency = '$';
export const CURRENCIES: Currency[] = ['$', '€', '£'];

type SettingsState = {
  locale: Locale;
  currency: Currency;
  /** Persist + apply a new locale (subscribers re-render via the store). */
  setLocale: (locale: Locale) => void;
  /** Persist + apply the price-glyph currency symbol. */
  setCurrency: (currency: Currency) => void;
  /** Load saved settings on boot; falls back to the Spanish / "$" defaults. */
  hydrate: () => Promise<void>;
};

export const useSettingsStore = create<SettingsState>((set) => ({
  locale: DEFAULT_LOCALE,
  currency: DEFAULT_CURRENCY,
  setLocale: (locale) => {
    const previous = useSettingsStore.getState().locale;
    set({ locale });
    void SecureStore.setItemAsync(LOCALE_KEY, locale);

    // Anything the SERVER localizes has to be re-asked for, because changing the
    // language here only changes the `Accept-Language` on the NEXT request — the
    // cached payload was rendered for the old one. Opening hours are the visible
    // case (T-168): the API now writes those lines itself, so a cached place
    // would show a Spanish UI with an English week until something refetched it.
    // Scoped to the places tree rather than clearing the cache, and skipped when
    // the language did not actually change (this setter runs on every hydrate).
    if (previous !== locale) {
      void queryClient.invalidateQueries({ queryKey: ['places'] });
    }
  },
  setCurrency: (currency) => {
    set({ currency });
    void SecureStore.setItemAsync(CURRENCY_KEY, currency);
  },
  hydrate: async () => {
    const [savedLocale, savedCurrency] = await Promise.all([
      SecureStore.getItemAsync(LOCALE_KEY),
      SecureStore.getItemAsync(CURRENCY_KEY),
    ]);
    if (savedLocale === 'es' || savedLocale === 'en') set({ locale: savedLocale });
    if (savedCurrency === '$' || savedCurrency === '€' || savedCurrency === '£') set({ currency: savedCurrency });
  },
}));
