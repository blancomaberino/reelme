import { cuisinePriceLine, platformIcon, priceGlyphs, relativeTime } from '../format';

describe('priceGlyphs', () => {
  it('maps 1–4 to € glyphs', () => {
    expect(priceGlyphs(1)).toBe('€');
    expect(priceGlyphs(3)).toBe('€€€');
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
});

describe('cuisinePriceLine', () => {
  it('joins present parts with a middot', () => {
    expect(cuisinePriceLine('ramen', 2)).toBe('ramen · €€');
    expect(cuisinePriceLine('ramen', null)).toBe('ramen');
    expect(cuisinePriceLine(null, 3)).toBe('€€€');
    expect(cuisinePriceLine(null, null)).toBe('');
  });
});
