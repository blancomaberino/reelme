import { directionsUrl, googleMapsUrl, placeShareUrl } from '../directions';

describe('directionsUrl', () => {
  it('routes to the exact coordinates on iOS (no name search)', () => {
    expect(directionsUrl(38.7169, -9.1355, 'Time Out Market', 'ios')).toBe(
      'http://maps.apple.com/?daddr=38.7169,-9.1355&dirflg=d',
    );
  });

  it('builds a geo: intent on Android', () => {
    expect(directionsUrl(38.7169, -9.1355, 'Time Out Market', 'android')).toBe(
      'geo:38.7169,-9.1355?q=38.7169,-9.1355(Time%20Out%20Market)',
    );
  });

  it('url-encodes names with special characters on Android', () => {
    expect(directionsUrl(0, 0, 'Café & Bar', 'android')).toContain('(Caf%C3%A9%20%26%20Bar)');
  });
});

describe('placeShareUrl', () => {
  it('produces the reelmap deep link', () => {
    expect(placeShareUrl('clara-cafe-abc123')).toBe('reelmap://place/clara-cafe-abc123');
  });
});

describe('googleMapsUrl', () => {
  it('builds a place-pinned Maps URL when a place id is present', () => {
    expect(googleMapsUrl('Clara Café', 'ChIJabc')).toBe(
      'https://www.google.com/maps/search/?api=1&query=Clara%20Caf%C3%A9&query_place_id=ChIJabc',
    );
  });
  it('returns null without a place id', () => {
    expect(googleMapsUrl('Clara Café', null)).toBeNull();
  });
});
