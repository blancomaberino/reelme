import { type Palette, schemes } from '../colors';
import { AA_NORMAL, contrastRatio, luminance } from '../contrast';

// T-101. Before this existed the palette had drifted to where the primary
// button's own label sat at 4.0:1 and the app's most-used text colour at 3.1:1 —
// nobody noticed, because nothing measured. This file is the thing that
// measures. Every pair below is a real foreground/background combination the app
// renders; adding a token means adding its pairs here.

type Token = keyof Palette;

/** Surfaces any body text can land on. */
const CANVASES: Token[] = ['background', 'surface', 'surface2'];

/** Tokens used as a text or icon colour on a canvas (see `color: c.*` usage). */
const FOREGROUNDS: Token[] = [
  'text',
  'ink2',
  'muted',
  'placeholder',
  'primary',
  'secondary',
  'gold',
  'green',
  'danger',
];

/**
 * Text-bearing pairs that are NOT foreground-on-canvas: accent fills under their
 * `onPrimary` label, and the soft tint chips that carry their own accent as text
 * (tag chip, rating chip, "saved" chip).
 */
const TINTED_PAIRS: [Token, Token][] = [
  ['onPrimary', 'primary'],
  ['onPrimary', 'primaryPressed'],
  ['onPrimary', 'danger'],
  // The map's SELECTED pin is a gold teardrop carrying a white price glyph
  // (components/map/pin-glyph.tsx reads both ends off this palette).
  ['onPrimary', 'gold'],
  ['primary', 'primarySoft'],
  ['secondary', 'secondarySoft'],
  ['gold', 'goldSoft'],
  ['green', 'greenSoft'],
  ['danger', 'dangerSoft'],
  ['text', 'primarySoft'],
  ['text', 'secondarySoft'],
  ['text', 'goldSoft'],
  ['text', 'greenSoft'],
  ['text', 'dangerSoft'],
];

const ALL_PAIRS: [Token, Token][] = [
  ...FOREGROUNDS.flatMap((fg): [Token, Token][] => CANVASES.map((bg) => [fg, bg])),
  ...TINTED_PAIRS,
];

describe.each(['light', 'dark'] as const)('%s scheme meets WCAG AA', (scheme) => {
  const palette = schemes[scheme];

  // Held to the NORMAL-text threshold throughout, not the 3:1 large-text one:
  // these tokens are used at 11–16px far more often than at heading sizes, and a
  // guard that assumed the generous case would wave through the failures this
  // task exists to fix.
  it.each(ALL_PAIRS)('%s on %s is at least 4.5:1', (fg, bg) => {
    expect(contrastRatio(palette[fg], palette[bg])).toBeGreaterThanOrEqual(AA_NORMAL);
  });
});

describe('scheme integrity', () => {
  it('defines the same tokens in both schemes', () => {
    expect(Object.keys(schemes.dark).sort()).toEqual(Object.keys(schemes.light).sort());
  });

  it('holds every token as a #RRGGBB literal', () => {
    // `luminance` throws on a malformed hex, so this also proves the guard above
    // scored real colours rather than silently treating a typo as black.
    for (const [scheme, palette] of Object.entries(schemes)) {
      for (const [token, hex] of Object.entries(palette)) {
        expect(`${scheme}.${token}=${hex}`).toMatch(/=#[0-9A-F]{6}$/);
        expect(() => luminance(hex)).not.toThrow();
      }
    }
  });

  it('keeps the pressed shade distinguishable from the resting accent', () => {
    // The AA fix darkened `primary` onto what used to BE `primaryPressed`. If a
    // later edit lets them converge, every button silently loses its press
    // feedback — with no visual test to catch it.
    for (const palette of Object.values(schemes)) {
      expect(palette.primaryPressed).not.toEqual(palette.primary);
      expect(contrastRatio(palette.primary, palette.primaryPressed)).toBeGreaterThan(1.15);
    }
  });

  it('keeps the skeleton fill perceptible on every canvas it lands on', () => {
    // T-108. `skeleton` carries no text, so AA does not apply — but it is the
    // whole content of a loading screen, and a fill that blends into the paper
    // is a blank screen with extra steps. It has to read on BOTH canvases:
    // place detail draws skeletons on `background`, my-places inside a `surface`
    // card. 1.25:1 is where the block stops being a shape and starts being a
    // smudge; the shipped values sit at 1.33–1.52.
    for (const palette of Object.values(schemes)) {
      for (const canvas of ['background', 'surface'] as const) {
        expect(contrastRatio(palette.skeleton, palette[canvas])).toBeGreaterThanOrEqual(1.25);
      }
    }
  });

  it('keeps the skeleton fill quieter than body text, so it never reads as content', () => {
    // The failure this guards is a skeleton darkened until the placeholder bars
    // look like real, unreadable text.
    for (const palette of Object.values(schemes)) {
      expect(contrastRatio(palette.skeleton, palette.background)).toBeLessThan(
        contrastRatio(palette.muted, palette.background),
      );
    }
  });

  it('keeps placeholder no louder than the caption colour it sits beneath', () => {
    // Hierarchy check: AA compresses `muted` and `placeholder` toward each other,
    // and it would be easy to overshoot and leave placeholders *louder* than
    // captions. Light: both darken, so placeholder must stay lighter than muted.
    // Dark: both lift, so placeholder must stay darker.
    expect(luminance(schemes.light.placeholder)).toBeGreaterThan(luminance(schemes.light.muted));
    expect(luminance(schemes.dark.placeholder)).toBeLessThan(luminance(schemes.dark.muted));
  });
});
