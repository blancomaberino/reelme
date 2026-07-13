import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

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
    set({ locale });
    void SecureStore.setItemAsync(LOCALE_KEY, locale);
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
