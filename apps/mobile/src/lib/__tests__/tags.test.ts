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
});
