import { fonts } from './colors';

/**
 * The non-colour half of the design system (T-104).
 *
 * Until now `colors.ts` WAS the design system, and everything else was a raw
 * number typed at the call site. The result was a drift field: seven spacing
 * values in heavy use plus one-offs at 3/5/7/9/13, four common radii plus
 * eleven strays, and twenty distinct font sizes including 17/19/21/27 — sizes
 * that exist because somebody nudged a number until it looked right, not
 * because the scale has a step there.
 *
 * These scales are deliberately *descriptive first*: each step is a value the
 * app already leaned on (12 appeared 111×, 8 84×, 16 47×), so adopting them is
 * a rename, not a redesign. The strays are what disappear.
 *
 * Paired with the ESLint rule in `eslint.config.js`, which bans raw numeric
 * `fontSize` / `borderRadius` / padding in NEW files so the field cannot regrow
 * while the existing screens migrate opportunistically.
 */

/**
 * Spacing, in points. Roughly a 4-step ramp — the two smallest steps stay
 * because icon/label gaps genuinely need them, and the jump from `lg` to `xl`
 * is where "inside a component" becomes "between components".
 */
export const space = {
  /**
   * 0 — for RESETTING a platform default, not for expressing a gap.
   *
   * A bare `padding: 0` is what the lint rule bans, and rightly: a raw number in
   * a stylesheet is how the drift field grew. But zero is a real value with one
   * real use — `TextInput` ships with inner padding on Android that has to be
   * removed for the field to sit on the same baseline as its icons — and the
   * rule's own instruction for a value the scale lacks is to change the scale.
   */
  none: 0,
  /** 4 — hairline gaps: an icon and its label. */
  xxs: 4,
  /** 8 — tight grouping inside a row. */
  xs: 8,
  /** 12 — the app's default gap (111 uses). */
  sm: 12,
  /** 16 — screen gutter, card padding. */
  md: 16,
  /** 24 — between sections. */
  lg: 24,
  /** 32 — around a lone empty state. */
  xl: 32,
  /** 48 — top padding on a centred empty/error screen. */
  xxl: 48,
} as const;

/**
 * Corner radii. `pill` is a large constant rather than a computed half-height:
 * React Native clamps it to half the smaller side, so one value serves every
 * chip and circular button.
 */
export const radius = {
  /** 8 — inputs, small tiles. */
  sm: 8,
  /** 12 — cards, sheets (30 uses). */
  md: 12,
  /** 16 — large surfaces, modals. */
  lg: 16,
  /** Fully rounded — chips, avatars, FABs. */
  pill: 999,
} as const;

/**
 * The type ramp.
 *
 * `display` and `hero` carry the serif (MERCADO's voice, used for place names
 * and screen titles); everything below them is the system face, because body
 * copy in Georgia at 15pt is where the art direction stops helping and starts
 * costing legibility.
 *
 * Sizes are NOT paired with a colour — colour is the palette's job, and binding
 * the two here would make every "same size, different emphasis" case a new
 * token.
 */
export const type = {
  /** 12 — timestamps, meta, chip labels. */
  caption: { fontSize: 12, fontWeight: '500' },
  /** 13 — secondary body, hints (59 uses). */
  bodySm: { fontSize: 13, fontWeight: '400' },
  /** 15 — default body (61 uses, the app's most common size). */
  body: { fontSize: 15, fontWeight: '400' },
  /** 16 — emphasised body, button labels. */
  bodyLg: { fontSize: 16, fontWeight: '600' },
  /** 20 — screen titles in a back header. */
  title: { fontSize: 20, fontWeight: '700', fontFamily: fonts.display },
  /** 22 — the standalone screen title on a root tab. */
  display: { fontSize: 22, fontWeight: '700', fontFamily: fonts.display },
  /** 28 — the one-per-screen hero (welcome, empty-state headline). */
  hero: { fontSize: 28, fontWeight: '800', letterSpacing: -0.5, fontFamily: fonts.display },
} as const;

export type Space = keyof typeof space;
export type Radius = keyof typeof radius;
export type TypeStep = keyof typeof type;
