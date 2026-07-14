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
    // Route to the exact coordinates. `daddr` alone pins the destination; a `q`
    // here makes Apple Maps do a NAME SEARCH instead and land on some other
    // same-named place (the reported "wrong location" bug).
    return `http://maps.apple.com/?daddr=${coords}&dirflg=d`;
  }
  return `geo:${coords}?q=${coords}(${encodeURIComponent(name)})`;
}

/** Canonical deep link for the native share sheet. */
export function placeShareUrl(slug: string): string {
  return `reelmap://place/${slug}`;
}

/** Deep link that opens a shared list in the app (T-063). */
export function listShareUrl(publicSlug: string): string {
  return `reelmap://list/${publicSlug}`;
}

/**
 * Human-facing web URL for a shared list (T-063) — the API serves `/l/{slug}`.
 * Derived from the configured API origin; null when unset (dev without a host).
 */
export function listWebUrl(publicSlug: string, base = process.env.EXPO_PUBLIC_API_URL): string | null {
  if (!base) return null;
  return `${base.replace(/\/+$/, '')}/l/${publicSlug}`;
}

/**
 * A link to the place's Google Maps page. `query_place_id` pins the exact
 * place; `query` (the name) is the required human-readable fallback per the
 * Maps URL API. Returns null when there's no Google place id.
 */
export function googleMapsUrl(name: string, placeId: string | null): string | null {
  if (!placeId) return null;
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(name)}&query_place_id=${encodeURIComponent(placeId)}`;
}
