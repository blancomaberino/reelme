import { useMemo } from 'react';

import { useSettingsStore } from '@/stores/settings';

import { priceGlyphs } from './format';
import { localizeTag } from './tags';

/**
 * Locale- and currency-aware display helpers, bound to the settings store so a
 * language or currency change re-renders subscribers:
 * - `price(tier)`     → currency glyphs in the chosen symbol
 * - `tag(raw)`        → a localized category/cuisine/tag label
 * - `priceLine(c, t)` → "Localized category · $$" (blank parts dropped)
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
    }),
    [locale, currency],
  );
}
