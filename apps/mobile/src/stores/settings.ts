import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

import { LOCALIZED_KEY_PREFIXES } from '@/api/keys';
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

/**
 * Anything the SERVER localizes is re-asked for whenever the language changes.
 *
 * Switching language only changes `Accept-Language` on the NEXT request, so a
 * cached payload is still the one the API rendered for the old one. Opening
 * hours are the visible case (T-168): the API writes those lines itself, so a
 * cached place shows an English week under a Spanish UI until something
 * refetches it.
 *
 * **A subscription, not a call inside each setter, and that is the whole
 * lesson.** The first version put the rule in `setLocale`; `hydrate()` — the
 * cold start of every user who ever switched, and so the path most likely to
 * change the language — wrote the field directly and skipped it. Moving it to
 * both writers only moved the problem: a third writer, including a bare
 * `useSettingsStore.setState({ locale })` from anywhere, would skip it again,
 * and the test enumerating the store's own methods could not see that either.
 *
 * Subscribing puts the rule where the STATE changes rather than where a caller
 * happens to be, so it holds for every writer that exists and every writer that
 * will. See CLAUDE.md, Wiring & seams #5.
 *
 * Scoped to the keys the server actually localizes ({@link LOCALIZED_KEY_PREFIXES},
 * which lives beside the key factory so it is under the nose of whoever adds the
 * next localized endpoint) — not to everything, since clearing the whole cache
 * would also discard the payload that restores a session offline.
 */
useSettingsStore.subscribe((state, previous) => {
  if (state.locale === previous.locale) {
    return;
  }

  for (const queryKey of LOCALIZED_KEY_PREFIXES) {
    void queryClient.invalidateQueries({ queryKey });
  }
});
