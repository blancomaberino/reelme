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

/** Coarse relative time ("3h", "2d", "Just now") from an ISO timestamp. */
export function relativeTime(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const secs = Math.max(0, Math.floor((now.getTime() - then) / 1000));
  if (secs < 60) return 'Just now';
  const mins = Math.floor(secs / 60);
  if (mins < 60) return `${mins}m`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days}d`;
  const weeks = Math.floor(days / 7);
  if (weeks < 5) return `${weeks}w`;
  const months = Math.floor(days / 30);
  if (months < 12) return `${months}mo`;
  return `${Math.floor(days / 365)}y`;
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
