import {
  cuisinePriceLine,
  distanceLabel,
  elapsedSince,
  platformIcon,
  priceGlyphs,
  relativeTime,
} from '../format';

describe('priceGlyphs', () => {
  it('maps 1–4 to currency glyphs (defaults to $)', () => {
    expect(priceGlyphs(1)).toBe('$');
    expect(priceGlyphs(3)).toBe('$$$');
    expect(priceGlyphs(2, '€')).toBe('€€');
  });
  it('returns empty for null / out of range', () => {
    expect(priceGlyphs(null)).toBe('');
    expect(priceGlyphs(0)).toBe('');
    expect(priceGlyphs(5)).toBe('');
    expect(priceGlyphs(undefined)).toBe('');
  });
});

describe('platformIcon', () => {
  it('maps known platforms', () => {
    expect(platformIcon('instagram')).toBe('logo-instagram');
    expect(platformIcon('tiktok')).toBe('logo-tiktok');
    expect(platformIcon('youtube')).toBe('logo-youtube');
    expect(platformIcon('x')).toBe('logo-twitter');
  });
  it('falls back to a link glyph', () => {
    expect(platformIcon('mystery')).toBe('link');
  });
});

describe('relativeTime', () => {
  const now = new Date(2026, 6, 15, 12, 0, 0);
  it('formats coarse buckets', () => {
    expect(relativeTime(new Date(now.getTime() - 30_000).toISOString(), now)).toBe('Just now');
    expect(relativeTime(new Date(now.getTime() - 5 * 60_000).toISOString(), now)).toBe('5m');
    expect(relativeTime(new Date(now.getTime() - 3 * 3_600_000).toISOString(), now)).toBe('3h');
    expect(relativeTime(new Date(now.getTime() - 2 * 86_400_000).toISOString(), now)).toBe('2d');
  });
  it('returns empty for null / invalid', () => {
    expect(relativeTime(null, now)).toBe('');
    expect(relativeTime('not-a-date', now)).toBe('');
  });

  /**
   * The month→year handover used to straddle two different definitions of a
   * month: the month branch divides by 30, the year branch divided by 365. That
   * leaves 360–364 days belonging to neither — already 12 months, not yet a
   * year — and it rendered as "0y".
   */
  it('never reports zero of a unit at the month/year boundary', () => {
    const daysAgo = (n: number) => new Date(now.getTime() - n * 86_400_000).toISOString();

    expect(relativeTime(daysAgo(359), now)).toBe('11mo');
    expect(relativeTime(daysAgo(360), now)).toBe('1y');
    expect(relativeTime(daysAgo(364), now)).toBe('1y');
    expect(relativeTime(daysAgo(400), now)).toBe('1y');
    expect(relativeTime(daysAgo(760), now)).toBe('2y');
  });
});

describe('elapsedSince', () => {
  const now = new Date(2026, 6, 15, 12, 0, 0);

  it('reports the unit and count separately so callers can translate it', () => {
    expect(elapsedSince(new Date(now.getTime() - 30_000).toISOString(), now)).toEqual({ unit: 'now', value: 0 });
    expect(elapsedSince(new Date(now.getTime() - 5 * 60_000).toISOString(), now)).toEqual({
      unit: 'minute',
      value: 5,
    });
    expect(elapsedSince(new Date(now.getTime() - 3 * 86_400_000).toISOString(), now)).toEqual({
      unit: 'day',
      value: 3,
    });
  });

  it('returns null for null / invalid, so the caller can omit the label', () => {
    expect(elapsedSince(null, now)).toBeNull();
    expect(elapsedSince('not-a-date', now)).toBeNull();
  });
});

describe('cuisinePriceLine', () => {
  it('joins present parts with a middot', () => {
    expect(cuisinePriceLine('ramen', 2)).toBe('ramen · $$');
    expect(cuisinePriceLine('ramen', null)).toBe('ramen');
    expect(cuisinePriceLine(null, 3)).toBe('$$$');
    expect(cuisinePriceLine(null, null)).toBe('');
  });
});

describe('distanceLabel', () => {
  it('reads metres under a kilometre, rounded to whole metres', () => {
    expect(distanceLabel(0)).toBe('0 m');
    expect(distanceLabel(48.4)).toBe('48 m');
    expect(distanceLabel(999.4)).toBe('999 m');
  });

  it('switches to kilometres AT 1000 m, keeping one decimal to 9.9 km', () => {
    // The boundary in both directions: 999.6 must not read "1000 m", and 1000
    // must not read "1.0 km" as "1000 m" either.
    expect(distanceLabel(999.6)).toBe('1.0 km');
    expect(distanceLabel(1000)).toBe('1.0 km');
    expect(distanceLabel(1240)).toBe('1.2 km');
    expect(distanceLabel(9949)).toBe('9.9 km');
  });

  it('drops the decimal past 10 km — precision a GPS fix does not have', () => {
    expect(distanceLabel(9950)).toBe('10 km');
    expect(distanceLabel(12_400)).toBe('12 km');
  });

  it('uses the locale decimal separator', () => {
    expect(distanceLabel(1240, ',')).toBe('1,2 km');
    // Metres carry no separator, so the locale must not change them.
    expect(distanceLabel(450, ',')).toBe('450 m');
  });

  it('returns "" for anything it cannot honestly render', () => {
    // '' rather than "0 m": a caller renders it conditionally, and "0 m" is a
    // real distance that a missing value must never be mistaken for.
    expect(distanceLabel(null)).toBe('');
    expect(distanceLabel(undefined)).toBe('');
    expect(distanceLabel(Number.NaN)).toBe('');
    expect(distanceLabel(Number.POSITIVE_INFINITY)).toBe('');
    expect(distanceLabel(-5)).toBe('');
  });
});
