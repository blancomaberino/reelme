import { useMemo } from 'react';

import { type Locale, useSettingsStore } from '@/stores/settings';

import { distanceLabel, priceGlyphs } from './format';
import { localizeTag } from './tags';

// Short month names — manual so we don't depend on Intl (Hermes ships only a
// partial Intl and toLocaleDateString options aren't reliable).
const MONTHS: Record<Locale, string[]> = {
  es: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
  en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};

function shortDate(iso: string, locale: Locale): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const day = d.getDate();
  const month = MONTHS[locale][d.getMonth()];
  const year = d.getFullYear();
  return locale === 'es' ? `${day} ${month} ${year}` : `${month} ${day}, ${year}`;
}

/**
 * Locale- and currency-aware display helpers, bound to the settings store so a
 * language or currency change re-renders subscribers:
 * - `price(tier)`     → currency glyphs in the chosen symbol
 * - `tag(raw)`        → a localized category/cuisine/tag label
 * - `priceLine(c, t)` → "Localized category · $$" (blank parts dropped)
 * - `distance(m)`     → "450 m" / "1,2 km" in the locale's number style
 */
export function useFormat() {
  const locale = useSettingsStore((s) => s.locale);
  const currency = useSettingsStore((s) => s.currency);
  return useMemo(
    () => ({
      price: (tier: number | null | undefined) => priceGlyphs(tier, currency),
      tag: (raw: string | null | undefined) => localizeTag(raw, locale),
      priceLine: (category: string | null, priceRange: number | null) =>
        [localizeTag(category, locale), priceGlyphs(priceRange, currency)].filter(Boolean).join(' · '),
      date: (iso: string | null | undefined) => (iso ? shortDate(iso, locale) : ''),
      // Spanish writes 1,2 km. Hermes ships only a partial Intl, so the
      // separator is carried here rather than asked for at format time.
      distance: (meters: number | null | undefined) =>
        distanceLabel(meters, locale === 'es' ? ',' : '.'),
    }),
    [locale, currency],
  );
}
