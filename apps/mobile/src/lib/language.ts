import type { Locale } from '@/stores/settings';

/** Localized names for the languages a food post is commonly written in. */
const NAMES: Record<string, Record<Locale, string>> = {
  en: { es: 'inglés', en: 'English' },
  es: { es: 'español', en: 'Spanish' },
  pt: { es: 'portugués', en: 'Portuguese' },
  fr: { es: 'francés', en: 'French' },
  it: { es: 'italiano', en: 'Italian' },
  de: { es: 'alemán', en: 'German' },
  ja: { es: 'japonés', en: 'Japanese' },
  ko: { es: 'coreano', en: 'Korean' },
  ar: { es: 'árabe', en: 'Arabic' },
};

/**
 * The display name of a BCP-47 language code in the app's language (T-…), e.g.
 * `('en', 'es') → "inglés"`. Returns null for an unknown/absent code so the
 * caller can simply omit the label.
 */
export function languageName(code: string | null | undefined, locale: Locale): string | null {
  if (!code) return null;
  const primary = code.toLowerCase().split('-')[0];
  return NAMES[primary]?.[locale] ?? null;
}
