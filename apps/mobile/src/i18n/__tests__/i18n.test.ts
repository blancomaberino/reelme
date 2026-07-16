import { en } from '../en';
import { es } from '../es';
import { translate } from '../index';

describe('i18n dictionaries', () => {
  it('es defines every key en does (no drift)', () => {
    expect(Object.keys(es).sort()).toEqual(Object.keys(en).sort());
  });

  it('no message is left empty', () => {
    const empties = (dict: Record<string, string>) => Object.values(dict).filter((v) => v.length === 0);
    expect(empties(es)).toHaveLength(0);
    expect(empties(en)).toHaveLength(0);
  });
});

describe('translate()', () => {
  it('returns Spanish for the es locale and English for en', () => {
    expect(translate('es', 'myPlaces.empty.title')).toBe('Aún no tienes lugares');
    expect(translate('en', 'myPlaces.empty.title')).toBe('No places yet');
  });

  it('interpolates named params', () => {
    expect(translate('en', 'place.shareMessage', { name: '1921' })).toBe('1921 on Reelmap');
    expect(translate('es', 'place.shareMessage', { name: '1921' })).toBe('1921 en Reelmap');
  });

  it('picks the singular/plural variant by count in both locales', () => {
    expect(translate('en', 'place.sourceCount', { count: 1 })).toBe('1 source');
    expect(translate('en', 'place.sourceCount', { count: 3 })).toBe('3 sources');
    expect(translate('es', 'place.sourceCount', { count: 1 })).toBe('1 fuente');
    expect(translate('es', 'place.sourceCount', { count: 3 })).toBe('3 fuentes');
  });

  it('falls back to the Spanish dictionary for an unknown locale (never throws)', () => {
    // @ts-expect-error — exercising the runtime guard for a bad locale value.
    expect(translate('fr', 'myPlaces.empty.title')).toBe('Aún no tienes lugares');
  });
});
