// Presentation helpers shared across discovery screens (place detail, feed, map).

/** Price tier 1–4 → currency glyphs ("$" by default); null/out-of-range → "". */
export function priceGlyphs(tier: number | null | undefined, symbol = '$'): string {
  if (typeof tier !== 'number' || tier < 1 || tier > 4) return '';
  return symbol.repeat(tier);
}

/** Ionicons glyph name for a social platform badge. */
export function platformIcon(platform: string): 'logo-instagram' | 'logo-tiktok' | 'logo-youtube' | 'logo-twitter' | 'link' {
  switch (platform) {
    case 'instagram':
      return 'logo-instagram';
    case 'tiktok':
      return 'logo-tiktok';
    case 'youtube':
      return 'logo-youtube';
    case 'x':
      return 'logo-twitter';
    default:
      return 'link';
  }
}

/** The largest whole unit that has elapsed since `iso`. */
export type ElapsedUnit = 'now' | 'minute' | 'hour' | 'day' | 'week' | 'month' | 'year';

export type Elapsed = { unit: ElapsedUnit; value: number };

/**
 * Coarse elapsed time, as a unit and a count — no words.
 *
 * Split out from {@see relativeTime} so a caller that needs the label in the
 * user's language can translate it. The suffixes are not the same everywhere
 * ("w" vs "sem", "mo" vs "mes"), and a Spanish screen reading "Just now" is the
 * kind of thing that only shows up on a device.
 *
 * Returns null for a missing or unparseable timestamp so the caller can omit
 * the label entirely rather than print a placeholder.
 */
export function elapsedSince(iso: string | null | undefined, now: Date = new Date()): Elapsed | null {
  if (!iso) return null;
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return null;
  const secs = Math.max(0, Math.floor((now.getTime() - then) / 1000));
  if (secs < 60) return { unit: 'now', value: 0 };
  const mins = Math.floor(secs / 60);
  if (mins < 60) return { unit: 'minute', value: mins };
  const hours = Math.floor(mins / 60);
  if (hours < 24) return { unit: 'hour', value: hours };
  const days = Math.floor(hours / 24);
  if (days < 7) return { unit: 'day', value: days };
  const weeks = Math.floor(days / 7);
  if (weeks < 5) return { unit: 'week', value: weeks };
  const months = Math.floor(days / 30);
  if (months < 12) return { unit: 'month', value: months };
  return { unit: 'year', value: Math.floor(days / 365) };
}

/** English suffix per unit — the shape {@see relativeTime} has always emitted. */
const EN_SUFFIX: Record<Exclude<ElapsedUnit, 'now'>, string> = {
  minute: 'm',
  hour: 'h',
  day: 'd',
  week: 'w',
  month: 'mo',
  year: 'y',
};

/**
 * Coarse relative time ("3h", "2d", "Just now") from an ISO timestamp.
 *
 * English-only; for anything user-facing on a localized screen use
 * {@see elapsedSince} and translate the unit.
 */
export function relativeTime(iso: string | null | undefined, now: Date = new Date()): string {
  const elapsed = elapsedSince(iso, now);
  if (!elapsed) return '';

  return elapsed.unit === 'now' ? 'Just now' : `${elapsed.value}${EN_SUFFIX[elapsed.unit]}`;
}

/**
 * Compact one-line label for a place's cuisine + price. `category` is passed
 * through verbatim — callers wanting a localized label should use the
 * `useFormat()` hook (which localizes the category and applies the currency).
 */
export function cuisinePriceLine(category: string | null, priceRange: number | null, symbol = '$'): string {
  const price = priceGlyphs(priceRange, symbol);
  return [category, price].filter(Boolean).join(' · ');
}
