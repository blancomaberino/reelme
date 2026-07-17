import { hasSpanishTag, localizeTag } from '../tags';

// The controlled vibe/dietary vocabulary — MUST mirror the `vibe_tags` and
// `dietary_tags` enums in packages/contracts/extraction.schema.json. Every one of
// these has to have a Spanish translation (they're a bounded set); a schema enum
// change should be reflected here and will fail the coverage test below until the
// dictionary is updated.
const VIBE_TAGS = [
  'cozy', 'romantic', 'lively', 'quiet', 'casual', 'upscale', 'trendy', 'minimalist',
  'rustic', 'family friendly', 'outdoor seating', 'rooftop', 'great view',
  'good for groups', 'date night', 'counter seating', 'pet friendly', 'live music',
  'brunch spot', 'late night', 'quick eats', 'hidden gem', 'spacious',
];
const DIETARY_TAGS = [
  'vegan', 'vegan options', 'vegetarian', 'vegetarian options', 'gluten-free',
  'dairy-free', 'halal', 'kosher', 'organic', 'plant-based',
];

describe('localizeTag', () => {
  it('translates known tags to Spanish', () => {
    expect(localizeTag('japanese', 'es')).toBe('Japonesa');
    expect(localizeTag('Modern', 'es')).toBe('Moderno');
    expect(localizeTag('contemporary', 'es')).toBe('Contemporáneo'); // regression: was shown as "Contemporary"
    expect(localizeTag('fine dining', 'es')).toBe('Alta cocina');
  });

  it('title-cases unknown tags instead of dropping them', () => {
    expect(localizeTag('szechuan', 'es')).toBe('Szechuan');
    expect(localizeTag('MODERN', 'en')).toBe('Modern');
    expect(localizeTag('street food', 'en')).toBe('Street Food');
  });

  it('leaves English as the (title-cased) source language', () => {
    expect(localizeTag('japanese', 'en')).toBe('Japanese');
  });

  it('returns empty for null/empty', () => {
    expect(localizeTag(null, 'es')).toBe('');
    expect(localizeTag('', 'en')).toBe('');
  });

  // Guards the "some tags show in English on a Spanish profile" bug: EVERY value
  // in the controlled vibe + dietary vocabulary must have an explicit Spanish
  // entry (not the title-case fallback). Exhaustive over the schema enums, so a
  // new enum value can't ship without its translation.
  it('has a Spanish translation for every controlled vibe + dietary tag', () => {
    const missing = [...VIBE_TAGS, ...DIETARY_TAGS].filter((t) => !hasSpanishTag(t));
    expect(missing).toEqual([]);
  });
});
