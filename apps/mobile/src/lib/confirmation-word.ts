/**
 * Does what the user typed match the sentinel word they were asked for?
 *
 * Its own module because the hazard it guards is not visible from the screen's
 * test suite (which runs the English dictionary, where no character has a
 * locale-specific casing).
 */

/** Uppercase A–Z only, by code point. Cannot depend on any locale. */
function foldAscii(value: string): string {
  let out = '';

  for (const char of value) {
    const code = char.charCodeAt(0);
    out += code >= 97 && code <= 122 ? String.fromCharCode(code - 32) : char;
  }

  return out;
}

/**
 * Case- and whitespace-tolerant: the gate is about deliberate intent, not
 * typing accuracy, and an on-screen keyboard that auto-capitalises or a
 * suggestion bar that appends a space must not fail someone for something they
 * did not do.
 *
 * The casing is an explicit ASCII fold rather than `toUpperCase()`, and
 * emphatically not `toLocaleUpperCase()`. The locale-aware version uses the
 * DEVICE locale, independent of the app's language: on a Turkish or
 * Azerbaijani device `'eliminar'` uppercases to `'ELİMİNAR'` (dotted İ), which
 * can never equal `'ELIMINAR'` — a permanent lockout on account deletion, the
 * one flow Apple requires the app to offer (Guideline 5.1.1(v)).
 *
 * A bare `toUpperCase()` is correct today, but only because both sentinels
 * happen to be ASCII and because nobody later "improves" it to the locale-aware
 * call. `foldAscii` makes the property structural instead of remembered: it
 * cannot produce a non-ASCII character, whatever locale the device is in.
 */
export function matchesConfirmationWord(typed: string, word: string): boolean {
  return foldAscii(typed.trim()) === foldAscii(word);
}
