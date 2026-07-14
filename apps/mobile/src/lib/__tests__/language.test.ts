import { languageName } from '../language';

describe('languageName', () => {
  it('localizes a BCP-47 code into the app language', () => {
    expect(languageName('en', 'es')).toBe('inglés');
    expect(languageName('en', 'en')).toBe('English');
    expect(languageName('pt-BR', 'en')).toBe('Portuguese'); // primary subtag
    expect(languageName('ES', 'en')).toBe('Spanish'); // case-insensitive
  });

  it('returns null for an unknown or absent code', () => {
    expect(languageName('xx', 'es')).toBeNull();
    expect(languageName(null, 'en')).toBeNull();
    expect(languageName(undefined, 'es')).toBeNull();
  });
});
