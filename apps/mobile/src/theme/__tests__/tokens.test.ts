import { execFileSync } from 'node:child_process';
import path from 'node:path';

import { LEGACY_UNTOKENIZED } from '../../../eslint.tokens-legacy';
import { radius, space, type } from '@/theme/tokens';

/**
 * The tokens and the machinery that stops the drift field regrowing (T-104).
 *
 * The scales themselves are barely worth asserting — they are constants. What
 * IS worth pinning is the guard: an ESLint selector that silently matches
 * nothing looks exactly like a codebase with no violations, which is how this
 * rule shipped green-but-inert on the first attempt.
 */

const ROOT = path.resolve(__dirname, '../../..');

describe('the scales', () => {
  it('ascends without duplicates, so every step means something', () => {
    for (const scale of [space, radius]) {
      const values = Object.values(scale);
      expect(values).toEqual([...values].sort((a, b) => a - b));
      expect(new Set(values).size).toBe(values.length);
    }
  });

  it('ramps type sizes monotonically from caption to hero', () => {
    const sizes = Object.values(type).map((t) => t.fontSize);
    expect(sizes).toEqual([...sizes].sort((a, b) => a - b));
  });

  it('reserves the serif for the three heading steps only', () => {
    // MERCADO's voice belongs on titles; body copy in Georgia at 15pt is where
    // the art direction stops helping and starts costing legibility.
    const withSerif = Object.entries(type)
      .filter(([, t]) => 'fontFamily' in t)
      .map(([k]) => k);
    expect(withSerif).toEqual(['title', 'display', 'hero']);
  });
});

describe('the drift guard', () => {
  /**
   * The rule has to actually FIRE. esquery only applies regex attribute
   * matching to string values, so `Literal[value=/^\d+$/]` matches no numeric
   * literal at all — the first version of this rule reported zero violations
   * across 832 real ones and looked like a pass.
   */
  it('rejects a raw number on a tokenized property', () => {
    const output = lint('const s = { fontSize: 15, borderRadius: 12, paddingHorizontal: 8 };');

    expect(output).toHaveLength(3);
    expect(output[0].message).toContain('@/theme/tokens');
  });

  it('accepts the same properties when they come from the scale', () => {
    expect(
      lint("import { space, type } from '@/theme/tokens';\nconst s = { ...type.body, padding: space.md };"),
    ).toHaveLength(0);
  });

  it('leaves untokenized properties alone', () => {
    // Colour is `useColors()`'s job, and width/height are layout, not rhythm.
    expect(lint('const s = { width: 26, opacity: 0.5, zIndex: 20 };')).toHaveLength(0);
  });

  it('never lets the legacy exemption list grow', () => {
    // An inverse allowlist only works if it shrinks. This number is the count
    // at the time T-104 landed; lowering it is the point, raising it defeats it.
    expect(LEGACY_UNTOKENIZED.length).toBeLessThanOrEqual(55);
  });

  it('escapes the [param] segments Expo Router filenames contain', () => {
    // minimatch reads `[slug]` as a character class, so an unescaped entry
    // matches nothing and the file is silently NOT exempted — which surfaces as
    // a lint failure nobody can explain.
    for (const entry of LEGACY_UNTOKENIZED) {
      if (entry.includes('[') || entry.includes(']')) {
        expect(entry).toMatch(/\\\[.+\\\]/);
      }
    }
  });
});

/** Run ESLint over a snippet at a path the token rule covers. */
function lint(source: string): { message: string }[] {
  let out: string;
  try {
    out = execFileSync(
      'npx',
      ['eslint', '--stdin', '--stdin-filename', 'src/__token_probe__.tsx', '-f', 'json'],
      { cwd: ROOT, input: source, encoding: 'utf8', stdio: ['pipe', 'pipe', 'ignore'] },
    );
  } catch (error) {
    // ESLint exits 1 when it reports errors — which is the case under test, so
    // the report is on stdout of the thrown result, not a failure to run.
    const stdout = (error as { stdout?: string }).stdout;
    if (typeof stdout !== 'string' || stdout === '') throw error;
    out = stdout;
  }

  return JSON.parse(out)[0].messages.filter(
    (m: { ruleId?: string }) => m.ruleId === 'no-restricted-syntax',
  );
}
