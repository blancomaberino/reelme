// Where the map opens (T-100). One ordered fallback chain, in one place, so the
// "which viewport wins?" question has a single answer that tests can pin.
import type { Region } from './geo';
import { getLocationPermission, getUserRegion, requestLocationPermission } from './location';

/**
 * Last resort only. Montevideo is where the seed/demo data lives, so it is the
 * least-bad frame for a user we know nothing about — but reaching this means
 * every better option failed, NOT that it is the app's home city.
 */
export const DEFAULT_REGION: Region = {
  latitude: -34.9,
  longitude: -56.16,
  latitudeDelta: 0.15,
  longitudeDelta: 0.15,
};

/** Which rung of the chain produced the region — drives what the UI does next. */
export type RegionSource = 'param' | 'saved' | 'user' | 'default';

export type InitialRegion = {
  region: Region;
  source: RegionSource;
  /**
   * True when we asked the OS for permission during this resolve (first launch
   * only) and it came back denied-and-unaskable — the screen surfaces the
   * one-time "enable it in Settings" hint off this.
   */
  permissionBlocked: boolean;
};

/**
 * A deep-linked `?lat=&lng=` pair as a region, or null when absent/unusable.
 *
 * Both params must be present AND finite: `Number('')` is 0, which would
 * otherwise centre a lat-only push on longitude 0 (the Gulf of Guinea).
 */
export function regionFromParams(lat: string | undefined, lng: string | undefined): Region | null {
  if ((lat ?? '') === '' || (lng ?? '') === '') return null;
  const latitude = Number(lat);
  const longitude = Number(lng);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return null;
  if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) return null;
  return { latitude, longitude, latitudeDelta: 0.02, longitudeDelta: 0.02 };
}

/**
 * The rungs that need no I/O, or null when none applies. Kept separate from
 * {@link resolveInitialRegion} so the map screen can take them on its FIRST
 * render — a returning user's map paints immediately, with no loading state.
 */
export function syncInitialRegion(input: {
  lat?: string;
  lng?: string;
  saved: Region | null;
  hydrated: boolean;
}): InitialRegion | null {
  const fromParams = regionFromParams(input.lat, input.lng);
  if (fromParams) return { region: fromParams, source: 'param', permissionBlocked: false };

  // Only trust `saved` once hydration has actually run — before that, null means
  // "haven't looked yet", not "nothing saved".
  if (input.hydrated && input.saved) {
    return { region: input.saved, source: 'saved', permissionBlocked: false };
  }

  return null;
}

/**
 * Resolve the map's opening viewport, in priority order:
 *
 *  1. **`?lat=&lng=` params** — an explicit "show me *this*" push (place detail
 *     → map, a notification deep link). Always wins; never blocks on I/O.
 *  2. **The last viewport the user settled on** — returning users expect the
 *     map where they left it, and we must not yank them to their GPS position
 *     just because they moved since. Read from the hydrated viewport store.
 *  3. **The device's location** — first launch only. This is the natural moment
 *     to ask: the user just opened a map. Permission is requested when still
 *     `undetermined`; an already-denied permission is never re-prompted here.
 *  4. **{@link DEFAULT_REGION}** — denied, no fix, or the fix timed out.
 *
 * Never throws and never hangs: `getUserRegion` self-limits, and every failure
 * degrades to the next rung.
 */
export async function resolveInitialRegion(input: {
  lat?: string;
  lng?: string;
  saved: Region | null;
}): Promise<InitialRegion> {
  const sync = syncInitialRegion({ ...input, hydrated: true });
  if (sync) return sync;

  // First launch. Ask only if the OS has not already been answered — a user who
  // said no once must not be re-prompted every cold start.
  const current = await getLocationPermission();
  const outcome = current.state === 'undetermined' ? await requestLocationPermission() : current;

  if (outcome.state === 'granted') {
    const user = await getUserRegion();
    if (user) return { region: user, source: 'user', permissionBlocked: false };
  }

  return {
    region: DEFAULT_REGION,
    source: 'default',
    permissionBlocked: outcome.state === 'denied' && !outcome.canAskAgain,
  };
}

/**
 * The user's position for an explicit "locate me" tap — here the prompt IS the
 * point, so an `undetermined` permission is always requested. Returns the
 * region, or the reason we can't provide one so the caller can explain itself.
 */
export async function locateUser(): Promise<
  { ok: true; region: Region } | { ok: false; reason: 'blocked' | 'denied' | 'unavailable' }
> {
  const current = await getLocationPermission();
  const outcome = current.state === 'granted' ? current : await requestLocationPermission();

  if (outcome.state !== 'granted') {
    // "blocked" = the OS will not prompt again, so the only fix is Settings.
    return { ok: false, reason: outcome.canAskAgain ? 'denied' : 'blocked' };
  }

  const region = await getUserRegion();
  return region ? { ok: true, region } : { ok: false, reason: 'unavailable' };
}
