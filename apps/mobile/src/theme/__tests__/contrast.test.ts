import { AA_LARGE, AA_NORMAL, contrastRatio, luminance } from '../contrast';

// T-101. The palette guard is only as trustworthy as this maths, so it is pinned
// against the WCAG reference values rather than against itself.

describe('luminance', () => {
  it('matches the WCAG reference values at the extremes', () => {
    expect(luminance('#000000')).toBe(0);
    expect(luminance('#FFFFFF')).toBe(1);
  });

  it('weights green above red above blue, as the coefficients require', () => {
    // A naive (r+g+b)/3 would make these equal — this is what catches that.
    expect(luminance('#00FF00')).toBeCloseTo(0.7152, 4);
    expect(luminance('#FF0000')).toBeCloseTo(0.2126, 4);
    expect(luminance('#0000FF')).toBeCloseTo(0.0722, 4);
  });

  it('applies the sRGB transfer curve, not the raw channel value', () => {
    // Mid-grey is ~0.216 in linear light, not 0.5. Skipping the curve would put
    // this at 0.5 and quietly overstate contrast against dark backgrounds.
    expect(luminance('#808080')).toBeCloseTo(0.2159, 3);
  });

  it.each(['808080', '#FFF', '#GGGGGG', '#1234567', 'rebeccapurple', ''])(
    'throws on %p rather than scoring it as black',
    (bad) => {
      // A guard that silently treats a typo as #000000 would report a *higher*
      // contrast than reality on a light canvas — passing the palette test for
      // exactly the wrong reason.
      expect(() => luminance(bad)).toThrow(/#RRGGBB/);
    },
  );

  it('accepts lower-case hex', () => {
    expect(luminance('#ffffff')).toBe(luminance('#FFFFFF'));
  });
});

describe('contrastRatio', () => {
  it('spans the full 1..21 range', () => {
    expect(contrastRatio('#000000', '#FFFFFF')).toBeCloseTo(21, 5);
    expect(contrastRatio('#7F7F7F', '#7F7F7F')).toBeCloseTo(1, 5);
  });

  it('is symmetric — argument order cannot change a verdict', () => {
    // The palette test lists pairs foreground-first; if order mattered, half of
    // them would be scoring the wrong way round.
    expect(contrastRatio('#B54A25', '#F6F0E6')).toBeCloseTo(
      contrastRatio('#F6F0E6', '#B54A25'),
      10,
    );
  });

  it('scores a known AA boundary the way a contrast checker does', () => {
    // The pair this task was filed over: white on the OLD terracotta.
    expect(contrastRatio('#FFFFFF', '#CF5C34')).toBeCloseTo(4.01, 2);
    // …and on the new one.
    expect(contrastRatio('#FFFFFF', '#B54A25')).toBeCloseTo(5.29, 2);
  });

  it('exposes the WCAG AA thresholds it is measured against', () => {
    expect(AA_NORMAL).toBe(4.5);
    expect(AA_LARGE).toBe(3);
  });
});
