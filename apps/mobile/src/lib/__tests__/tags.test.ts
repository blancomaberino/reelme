import { localizeTag } from '../tags';

describe('localizeTag', () => {
  it('translates known tags to Spanish', () => {
    expect(localizeTag('japanese', 'es')).toBe('Japonesa');
    expect(localizeTag('Modern', 'es')).toBe('Moderno');
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

  // Guards the "some tags show in English on a Spanish profile" bug: every value
  // in the controlled vibe/occasion vocabulary must have a Spanish translation.
  it('localizes the full controlled vibe/occasion vocabulary to Spanish', () => {
    const cases: [string, string][] = [
      ['rustic', 'Rústico'],
      ['spacious', 'Espacioso'],
      ['outdoor seating', 'Mesas afuera'],
      ['great view', 'Con vista'],
      ['good for groups', 'Para grupos'],
      ['pet friendly', 'Admite mascotas'],
      ['live music', 'Música en vivo'],
      ['brunch spot', 'Para brunch'],
      ['quick eats', 'Comida rápida'],
      ['cozy', 'Acogedor'],
      ['upscale', 'Elegante'],
      ['date night', 'Para una cita'],
      ['counter seating', 'Barra'],
    ];
    for (const [raw, es] of cases) {
      expect(localizeTag(raw, 'es')).toBe(es);
    }
  });
});
