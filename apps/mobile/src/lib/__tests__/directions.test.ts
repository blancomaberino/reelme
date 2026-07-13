import { directionsUrl, placeShareUrl } from '../directions';

describe('directionsUrl', () => {
  it('builds an Apple Maps URL on iOS', () => {
    expect(directionsUrl(38.7169, -9.1355, 'Time Out Market', 'ios')).toBe(
      'http://maps.apple.com/?daddr=38.7169,-9.1355&q=Time%20Out%20Market',
    );
  });

  it('builds a geo: intent on Android', () => {
    expect(directionsUrl(38.7169, -9.1355, 'Time Out Market', 'android')).toBe(
      'geo:38.7169,-9.1355?q=38.7169,-9.1355(Time%20Out%20Market)',
    );
  });

  it('url-encodes names with special characters', () => {
    expect(directionsUrl(0, 0, 'Café & Bar', 'ios')).toContain('q=Caf%C3%A9%20%26%20Bar');
  });
});

describe('placeShareUrl', () => {
  it('produces the reelmap deep link', () => {
    expect(placeShareUrl('clara-cafe-abc123')).toBe('reelmap://place/clara-cafe-abc123');
  });
});
