import * as SecureStore from 'expo-secure-store';
import { create } from 'zustand';

export type Locale = 'es' | 'en';

// Spanish is the default ("main app language must be Spanish"); the user can
// override it in Settings and the choice persists in SecureStore (already a
// dependency for the auth token — no extra native module / AsyncStorage rebuild).
const KEY = 'app_locale';
export const DEFAULT_LOCALE: Locale = 'es';

type SettingsState = {
  locale: Locale;
  /** Persist + apply a new locale (subscribers re-render via the store). */
  setLocale: (locale: Locale) => void;
  /** Load the saved locale on boot; falls back to the Spanish default. */
  hydrate: () => Promise<void>;
};

export const useSettingsStore = create<SettingsState>((set) => ({
  locale: DEFAULT_LOCALE,
  setLocale: (locale) => {
    set({ locale });
    void SecureStore.setItemAsync(KEY, locale);
  },
  hydrate: async () => {
    const saved = await SecureStore.getItemAsync(KEY);
    if (saved === 'es' || saved === 'en') set({ locale: saved });
  },
}));
