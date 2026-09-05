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
  // Years come from the SAME 30-day month the line above uses, not from 365.
  // Mixing the two leaves a five-day hole: 360–364 days is already 12 months,
  // so it falls through here, and `days / 365` truncates it to "0 y".
  return { unit: 'year', value: Math.floor(months / 12) };
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

/**
 * A distance in metres as a person reads it: "450 m", "1,2 km", "12 km".
 *
 * THE app's only distance renderer, extracted rather than written twice — the
 * venue-candidate picker had the first one inline (`Math.round(m)` and a key
 * hard-coding " m"), which read fine for a 40 m match and would have said
 * "3218 m" on the map. Both call sites go through here now.
 *
 * Rounding is by magnitude, not by a fixed precision, because precision the
 * source does not have is a lie of a different kind: a GPS fix is good to tens
 * of metres, so "1.23 km" claims a resolution nobody has. Under a kilometre it
 * rounds to whole metres; to 9.9 km it keeps one decimal (the difference between
 * a 1.2 km walk and a 1.9 km one is the difference between walking and not);
 * past that it rounds to whole kilometres.
 *
 * `decimal` is the locale's separator — Spanish writes 1,2 km. Passed in rather
 * than read from `Intl`, which Hermes ships only partially.
 *
 * Returns '' for a missing or non-finite value, so a caller can render it
 * conditionally without a second null check.
 */
export function distanceLabel(meters: number | null | undefined, decimal = '.'): string {
  if (typeof meters !== 'number' || !Number.isFinite(meters) || meters < 0) return '';

  // ROUND FIRST, THEN PICK THE UNIT. Choosing the unit from the raw value and
  // rounding afterwards puts the rounding on the wrong side of the boundary in
  // both directions: 999.6 m is "under a kilometre", so it rounds to 1000 and
  // prints "1000 m"; 9950 m is "under 10 km", so it keeps a decimal and prints
  // "10.0 km". Both were live here until the boundary cases were written down.
  const whole = Math.round(meters);
  if (whole < 1000) return `${whole} m`;

  const km = Math.round(meters / 100) / 10;
  // Grouped past a thousand kilometres: "1500 km" reads as a typo where
  // "1.500 km" reads as a distance. Reachable from a wide map.
  //
  // Grouped BY HAND, not with `toLocaleString`: Hermes ships only a partial Intl
  // (the reason `use-format.ts` carries a literal table of month names rather
  // than asking Intl for them), so a locale argument here is silently ignored on
  // device and correct in jest — green tests over a wrong screen. The group
  // separator is whichever of `.`/`,` the decimal separator is not.
  const wholeKm = Math.round(meters / 1000);
  if (wholeKm >= 1000) {
    return `${group(wholeKm, decimal === ',' ? '.' : ',')} km`;
  }
  if (km >= 10) return `${wholeKm} km`;

  return `${km.toFixed(1).replace('.', decimal)} km`;
}

/** Thousands separators for a non-negative integer, without Intl. */
function group(value: number, separator: string): string {
  return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, separator);
}
