import { foldSearch, haystackMatchIndex } from '@/lib/search-text';

describe('foldSearch', () => {
  it('lowercases and trims', () => {
    expect(foldSearch('  CAFÉ  ')).toBe('cafe');
  });

  it('folds the Spanish diacritics tags are full of', () => {
    expect(foldSearch('España')).toBe('espana');
    expect(foldSearch('Perú')).toBe('peru');
    expect(foldSearch('Curaçao')).toBe('curacao');
  });

  /**
   * The reason this moved out of `tags.ts`: the tag fold only knew the Spanish
   * vowels, so every country whose name needs anything else was unfindable by
   * typing it on an ASCII keyboard — silently, since "no matches" looks like a
   * legitimate answer.
   */
  it('folds the letters that only country names bring', () => {
    expect(foldSearch('Türkiye')).toBe('turkiye');
    expect(foldSearch('Åland')).toBe('aland');
    expect(foldSearch('São Tomé')).toBe('sao tome');
    expect(foldSearch('Côte d’Ivoire')).toBe('cote d’ivoire');
  });

  it('leaves ASCII and unknown characters alone', () => {
    expect(foldSearch('Uruguay 🇺🇾')).toBe('uruguay 🇺🇾');
  });
});

describe('haystackMatchIndex', () => {
  it('returns the earliest match across haystacks, so prefix beats mid-word', () => {
    expect(haystackMatchIndex(['chile', 'cl'], 'chi')).toBe(0);
    expect(haystackMatchIndex(['republica de chile'], 'chi')).toBe(13);
  });

  it('is -1 for no match and 0 for an empty query', () => {
    expect(haystackMatchIndex(['uruguay'], 'zz')).toBe(-1);
    expect(haystackMatchIndex(['uruguay'], '')).toBe(0);
  });

  it('does not carry regex state between calls', () => {
    // The fold uses a /g regex; a shared lastIndex would make the second call
    // skip the start of the string and quietly stop matching.
    expect(foldSearch('Türkiye')).toBe(foldSearch('Türkiye'));
  });
});
