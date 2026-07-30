// WCAG relative-luminance maths (T-101). Lives in src/ rather than a test file
// because the palette test is not its only conceivable consumer — and because a
// contrast helper that ships with the theme is the thing a future token addition
// reaches for. No react-native import: this is pure colour maths.

/** sRGB channel → linear-light, per WCAG 2.x relative-luminance. */
function linearize(channel: number): number {
  const s = channel / 255;
  return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
}

/**
 * Relative luminance of a `#RRGGBB` colour. Throws on anything else rather than
 * silently scoring a typo as black — a palette guard that quietly passes on a
 * malformed hex is worse than no guard.
 */
export function luminance(hex: string): number {
  if (!/^#[0-9a-fA-F]{6}$/.test(hex)) {
    throw new Error(`luminance() expects #RRGGBB, got ${JSON.stringify(hex)}`);
  }
  const n = parseInt(hex.slice(1), 16);
  const [r, g, b] = [(n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff].map(linearize);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * WCAG contrast ratio between two colours, 1..21. Symmetric — the lighter of the
 * pair is always the numerator, so argument order never changes the verdict.
 */
export function contrastRatio(a: string, b: string): number {
  const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (lighter + 0.05) / (darker + 0.05);
}

/** WCAG 2.1 AA thresholds. Large = ≥18.66px, or ≥14px bold. */
export const AA_NORMAL = 4.5;
export const AA_LARGE = 3;
