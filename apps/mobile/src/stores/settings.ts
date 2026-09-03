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

/**
 * The ONE place the in-memory locale changes, because a locale change has a
 * consequence beyond the field.
 *
 * Anything the SERVER localizes has to be re-asked for: switching language here
 * only changes `Accept-Language` on the NEXT request, so a cached payload is
 * still the one the API rendered for the old one. Opening hours are the visible
 * case (T-168) — the API writes those lines itself, so a cached place shows an
 * English week under a Spanish UI until something refetches it.
 *
 * Both writers go through this. The first version put the rule inside
 * `setLocale` only, and `hydrate()` — which restores the SAVED language on every
 * cold start, and is the path that actually differs from the default — wrote the
 * field directly and skipped it. Found by review, not by a test: the test
 * asserted the setter it was written beside rather than the invariant, so a
 * second writer of the same state was invisible to it.
 */
function applyLocale(set: (partial: Partial<SettingsState>) => void, next: Locale): void {
  if (useSettingsStore.getState().locale === next) {
    return;
  }

  set({ locale: next });
  // Scoped to the places tree — clearing the whole cache would also discard the
  // payload that restores a session offline.
  void queryClient.invalidateQueries({ queryKey: ['places'] });
}

export const useSettingsStore = create<SettingsState>((set) => ({
  locale: DEFAULT_LOCALE,
  currency: DEFAULT_CURRENCY,
  setLocale: (locale) => {
    applyLocale(set, locale);
    // Persisted here and NOT in applyLocale: hydrate() is reading this value
    // back, so writing it there would echo it straight to disk again.
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
    // Through applyLocale, not `set`: restoring a saved language IS a locale
    // change, and it is the one most likely to differ from the default — this is
    // the cold start of every user who has ever switched.
    if (savedLocale === 'es' || savedLocale === 'en') applyLocale(set, savedLocale);
    if (savedCurrency === '$' || savedCurrency === '€' || savedCurrency === '£') set({ currency: savedCurrency });
  },
}));
