/**
 * Case- and accent-insensitive text matching for the app's client-side search
 * boxes (tag filter, country picker).
 *
 * Lives here rather than in `tags.ts`, where it started: the country picker
 * needs exactly this, and importing it from a tag module would have been the
 * first step toward a second, slightly different copy.
 */

/**
 * Diacritic → ASCII, applied character by character.
 *
 * A table, not `String.normalize('NFD')`: Hermes does not ship full ICU, so
 * `normalize` is unreliable on device — and this runs on every keystroke, where
 * a table lookup is the cheap option anyway.
 *
 * The coverage is Latin-1 plus the letters that actually appear in localized
 * country names — Åland, Türkiye, Curaçao, Côte d'Ivoire, São Tomé, Réunion.
 * Typing "aland" or "turkiye" has to find them, and the Spanish-vowels-only
 * fold this replaced silently did not.
 */
const FOLD: Record<string, string> = {
  á: 'a', à: 'a', â: 'a', ä: 'a', ã: 'a', å: 'a', ā: 'a',
  é: 'e', è: 'e', ê: 'e', ë: 'e', ē: 'e', ě: 'e',
  í: 'i', ì: 'i', î: 'i', ï: 'i', ī: 'i', ı: 'i',
  ó: 'o', ò: 'o', ô: 'o', ö: 'o', õ: 'o', ø: 'o', ō: 'o',
  ú: 'u', ù: 'u', û: 'u', ü: 'u', ū: 'u',
  ñ: 'n', ń: 'n',
  ç: 'c', ć: 'c', č: 'c',
  ý: 'y', ÿ: 'y',
  š: 's', ś: 's', ș: 's',
  ž: 'z', ź: 'z', ż: 'z',
  ř: 'r', ł: 'l', đ: 'd', ț: 't', ğ: 'g',
  æ: 'ae', œ: 'oe', ß: 'ss',
};

/** Every character the table folds — so ASCII text skips the lookup entirely. */
const FOLDABLE = new RegExp(`[${Object.keys(FOLD).join('')}]`, 'g');

/**
 * Normalize text for search: lowercase, trim, and strip diacritics, so "Café",
 * "cafe" and "CAFÉ" — or "Türkiye" and "turkiye" — all compare equal.
 */
export function foldSearch(s: string): string {
  return s
    .toLowerCase()
    .trim()
    .replace(FOLDABLE, (ch) => FOLD[ch] ?? ch);
}

/**
 * Earliest index at which the folded query occurs in any haystack, or -1 for no
 * match (0 = starts-with, so callers can rank prefix matches ahead of mid-word).
 * An empty query matches everything at 0. The match is case-insensitive,
 * accent-insensitive, and substring ("part of the word").
 */
export function haystackMatchIndex(haystacks: string[], foldedQuery: string): number {
  if (!foldedQuery) return 0;
  let best = -1;
  for (const h of haystacks) {
    const i = h.indexOf(foldedQuery);
    if (i !== -1 && (best === -1 || i < best)) best = i;
  }
  return best;
}
