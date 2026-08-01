// Central palette — art direction "MERCADO" (see design/reelmap-design-v1 on
// claude.ai/design). Warm Lisbon-market paper, terracotta primary, azulejo
// secondary, market-gold for price/reviews. Two schemes (light/dark) resolved
// from the OS setting via `useColors()`; components build their StyleSheet
// through a `makeStyles(c)` factory so a scheme switch re-themes without
// remounting. `fonts.display` (serif) is used for place names and headings.
import { Platform, useColorScheme } from 'react-native';

export type Palette = {
  /** Brand accent — terracotta. Primary buttons, links, focus rings, pins. */
  primary: string;
  /** Pressed/active shade of the accent. */
  primaryPressed: string;
  /** Tint behind the accent — logo mark, soft fills. */
  primarySoft: string;
  /** Azulejo teal — links / secondary accents / tag chips. */
  secondary: string;
  secondarySoft: string;
  /** Market-gold — price and ratings. */
  gold: string;
  goldSoft: string;
  /** Published / open. */
  green: string;
  greenSoft: string;
  danger: string;
  dangerSoft: string;
  /** App canvas (warm paper). */
  background: string;
  /** Raised surfaces — cards, inputs. */
  surface: string;
  /** Recessed surface — filled inputs, rating cards. */
  surface2: string;
  /** Primary body text (roasted ink). */
  text: string;
  /** Secondary text. */
  ink2: string;
  /** Tertiary / captions. */
  muted: string;
  placeholder: string;
  border: string;
  /** Stronger hairline — dividers, input borders. */
  line2: string;
  /** Text that sits on top of the accent color. */
  onPrimary: string;
};

// T-101 — every token that carries TEXT was moved to clear WCAG AA (4.5:1) on
// each surface it actually lands on. The moves are lightness-only: measured in
// OKLCH, hue drifts at most 3.5° and chroma never increases, so this is the same
// art direction one step deeper, not a re-brand. (Terracotta stays at h≈39°,
// nowhere near the alarm-red h≈22° that MERCADO exists to replace; the
// low-chroma rule that keeps food photography leading is likewise preserved.)
//
// The paper itself — background / surface / surface2 / text / ink2 — is
// untouched. The canvas IS the art direction, so the accents moved instead.
//
// `theme/__tests__/colors.test.ts` pins every documented pair. Any token edit
// that drops a pair below AA fails the build; do not "fix" it by loosening the
// threshold.
const light: Palette = {
  primary: '#B54A25', // was #CF5C34 — 4.0:1 under its own white label, i.e. every CTA failed
  primaryPressed: '#93381A', // was #B84D28, which `primary` now occupies
  primarySoft: '#FDEDE2', // was #F7E1D5 — lifted so `primary` text clears AA on the chip
  secondary: '#356E86',
  secondarySoft: '#DFEBEE', // was #DBE8EC
  gold: '#8B5D00', // was #B4842A — the largest move; yellows start light, so AA costs L*
  goldSoft: '#F7EEDA', // was #F1E6C9
  green: '#377245', // was #4C8759
  greenSoft: '#E2EFE3', // was #DCEBDD
  danger: '#B2391F', // was #BC4329
  dangerSoft: '#F9E3DB', // was #F6DCD3
  background: '#F6F0E6',
  surface: '#FFFFFF',
  surface2: '#F4EDE1',
  text: '#241E17',
  ink2: '#5E5347',
  muted: '#6F6353', // was #938776 at 3.1:1 — the single most-used text colour (124 uses)
  placeholder: '#756957', // was #A99C89 at 2.7:1, the worst pair in the app
  border: '#E6DBC8',
  line2: '#D8CBB4',
  onPrimary: '#FFFFFF',
};

// Dark needed far less work — light-on-dark starts with more headroom. Only the
// two dimmest neutrals failed, and both moved UP.
const dark: Palette = {
  primary: '#E07A50',
  primaryPressed: '#C96A44',
  primarySoft: '#3A2517',
  secondary: '#6FA6BE',
  secondarySoft: '#1E2E36',
  gold: '#D2A24A',
  goldSoft: '#33290F',
  green: '#6FB27C',
  greenSoft: '#1E2C1F',
  danger: '#E06A50',
  dangerSoft: '#3A1C14',
  background: '#151109',
  surface: '#241D14',
  surface2: '#2C2418',
  text: '#F3EADA',
  ink2: '#C6B9A5',
  muted: '#A2957F', // was #8E8272 — 4.1:1 on surface2, and must stay above placeholder
  placeholder: '#988C7B', // was #6E6353 at 2.6:1
  border: '#332A1C',
  line2: '#41361F',
  onPrimary: '#1A1206',
};

/** Font families. Georgia ships on iOS; Android falls back to its serif. */
export const fonts = {
  display: Platform.select({ ios: 'Georgia', default: 'serif' }) as string,
  /**
   * Fixed-width, for strings read and transcribed character by character — the
   * 2FA secret and recovery codes (T-068). Named per platform rather than
   * hard-coding 'Courier', which is an iOS face: on Android it silently falls
   * back to the default proportional font, losing exactly the alignment that
   * makes an O distinguishable from a 0.
   */
  mono: Platform.select({ ios: 'Menlo', default: 'monospace' }) as string,
};

/**
 * Both schemes by name. Exported for the contrast test — a guard that could only
 * see whichever scheme `useColorScheme()` happened to return would police half
 * the palette. Screens should use {@link useColors}, not these.
 */
export const schemes = { light, dark } as const;

export function useColors(): Palette {
  return useColorScheme() === 'dark' ? dark : light;
}
