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

const light: Palette = {
  primary: '#CF5C34',
  primaryPressed: '#B84D28',
  primarySoft: '#F7E1D5',
  secondary: '#356E86',
  secondarySoft: '#DBE8EC',
  gold: '#B4842A',
  goldSoft: '#F1E6C9',
  green: '#4C8759',
  greenSoft: '#DCEBDD',
  danger: '#BC4329',
  dangerSoft: '#F6DCD3',
  background: '#F6F0E6',
  surface: '#FFFFFF',
  surface2: '#F4EDE1',
  text: '#241E17',
  ink2: '#5E5347',
  muted: '#938776',
  placeholder: '#A99C89',
  border: '#E6DBC8',
  line2: '#D8CBB4',
  onPrimary: '#FFFFFF',
};

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
  muted: '#8E8272',
  placeholder: '#6E6353',
  border: '#332A1C',
  line2: '#41361F',
  onPrimary: '#1A1206',
};

/** Font families. Georgia ships on iOS; Android falls back to its serif. */
export const fonts = {
  display: Platform.select({ ios: 'Georgia', default: 'serif' }) as string,
};

export function useColors(): Palette {
  return useColorScheme() === 'dark' ? dark : light;
}
