import { parseFlag } from '../feature-flags';

/**
 * A flag guarding an irreversible action has an asymmetric failure cost: read
 * OFF when it should be ON and a feature is missing; read ON when it should be
 * OFF and "delete my account" goes live against endpoints that do not exist.
 * So the parser is deliberately strict, and that strictness is pinned here.
 */
describe('parseFlag', () => {
  it('falls back when the variable is unset or blank', () => {
    expect(parseFlag(undefined, false)).toBe(false);
    expect(parseFlag('', false)).toBe(false);
    expect(parseFlag(undefined, true)).toBe(true);
  });

  it('accepts only the explicit truthy words, case-insensitively', () => {
    expect(parseFlag('1', false)).toBe(true);
    expect(parseFlag('true', false)).toBe(true);
    expect(parseFlag('TRUE', false)).toBe(true);
  });

  it('reads anything else as OFF — including plausible typos', () => {
    // The dangerous direction. `yes`/`on` look like they should work, which is
    // exactly why they must not silently enable an irreversible action.
    for (const raw of ['yes', 'on', 'True ', '0', 'false', 'enabled']) {
      expect(parseFlag(raw, false)).toBe(false);
    }
  });

  it('an explicit off value beats a true default', () => {
    expect(parseFlag('0', true)).toBe(false);
    expect(parseFlag('false', true)).toBe(false);
  });
});
