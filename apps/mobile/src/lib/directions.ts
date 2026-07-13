import { Platform } from 'react-native';

/**
 * Build a maps-app deep link routed to a place (T-033 action row).
 * iOS → Apple Maps; everything else → the `geo:` intent (Android) which the
 * OS resolves to Google Maps / the user's default.
 */
export function directionsUrl(
  lat: number,
  lng: number,
  name: string,
  os: typeof Platform.OS = Platform.OS,
): string {
  const coords = `${lat},${lng}`;
  if (os === 'ios') {
    return `http://maps.apple.com/?daddr=${coords}&q=${encodeURIComponent(name)}`;
  }
  return `geo:${coords}?q=${coords}(${encodeURIComponent(name)})`;
}

/** Canonical deep link for the native share sheet. */
export function placeShareUrl(slug: string): string {
  return `reelmap://place/${slug}`;
}
