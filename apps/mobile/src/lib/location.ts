// Device-location access for the map (T-100). Everything the app knows about
// expo-location lives here so the screens deal in plain `Region`s and a tiny
// three-state permission enum — and so the whole surface is mockable in one
// place. No react-native-maps import (same rule as `geo.ts`).
import { Linking } from 'react-native';

import type { Region } from './geo';

type LocationModule = typeof import('expo-location');

let cachedModule: LocationModule | null | undefined;

/**
 * `expo-location` resolves its native module AT IMPORT TIME, so a top-level
 * `import` throws outright — killing the whole screen — whenever the binary
 * lacks it: a dev client built before the dependency was added, Expo Go, or web.
 * Requiring it lazily turns that into a null we can fall back from, which is the
 * degradation every function below already documents. Resolved once and cached
 * (including the failure, as `null`) so a miss costs one try/catch, not one per
 * call.
 */
function locationModule(): LocationModule | null {
  if (cachedModule !== undefined) return cachedModule;
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    cachedModule = require('expo-location') as LocationModule;
  } catch {
    cachedModule = null;
  }
  return cachedModule;
}

/** Test seam: drop the memoized module so a suite can re-exercise the miss. */
export function __resetLocationModule(): void {
  cachedModule = undefined;
}

/**
 * How tight a viewport we open at when centring on the user. ~2km across —
 * close enough to read street context, wide enough that a handful of nearby
 * pins are already in frame.
 */
export const USER_REGION_DELTA = 0.02;

/**
 * A GPS fix can take a long time (cold start, indoors, tunnel). We are only
 * ever using it to *frame the map*, so cap the wait and fall back rather than
 * leave the user staring at a spinner. Callers that already have something to
 * show should pass a shorter budget.
 */
const FIX_TIMEOUT_MS = 5_000;

export type PermissionState = 'granted' | 'denied' | 'undetermined';

/**
 * Whether the OS will still show a prompt. `denied` + `canAskAgain: false` is
 * the "you must go to Settings" state — the only case where deep-linking to the
 * OS settings page is the honest next step.
 */
export type PermissionOutcome = { state: PermissionState; canAskAgain: boolean };

/**
 * No native module → denied AND permanently unaskable, so callers take their
 * fallback path silently instead of offering a prompt that can never appear.
 */
const UNAVAILABLE: PermissionOutcome = { state: 'denied', canAskAgain: false };

/**
 * Compared as strings rather than against `Location.PermissionStatus`: the enum
 * lives in the module we may not have, and these are its literal values.
 */
function toOutcome(response: { status: string; canAskAgain: boolean }): PermissionOutcome {
  const state: PermissionState =
    response.status === 'granted'
      ? 'granted'
      : response.status === 'denied'
        ? 'denied'
        : 'undetermined';
  return { state, canAskAgain: response.canAskAgain };
}

/** Current permission WITHOUT prompting — safe to call on mount. */
export async function getLocationPermission(): Promise<PermissionOutcome> {
  const m = locationModule();
  if (!m) return UNAVAILABLE;
  try {
    return toOutcome(await m.getForegroundPermissionsAsync());
  } catch {
    return UNAVAILABLE;
  }
}

/** Prompt for when-in-use permission. A no-op re-prompt if already decided. */
export async function requestLocationPermission(): Promise<PermissionOutcome> {
  const m = locationModule();
  if (!m) return UNAVAILABLE;
  try {
    return toOutcome(await m.requestForegroundPermissionsAsync());
  } catch {
    return UNAVAILABLE;
  }
}

/**
 * The user's position as a map region, or null if unavailable. Tries the OS's
 * cached last-known fix first (returns instantly, usually good enough to frame
 * a city-level viewport) before paying for a fresh one, and gives up after
 * `timeoutMs` so a hanging GPS never blocks the map.
 *
 * Assumes permission is already granted — callers own the prompt.
 */
export async function getUserRegion(
  timeoutMs: number = FIX_TIMEOUT_MS,
  lastKnown?: { maxAge?: number; requiredAccuracy?: number },
): Promise<Region | null> {
  const m = locationModule();
  if (!m) return null;

  try {
    // `lastKnown` bounds the CACHED fix, and callers that measure distances
    // must pass it. Unbounded, `getLastKnownPositionAsync` will happily hand
    // back a reading from another city hours ago — free when the answer only
    // has to frame a viewport (what this function was written for), and wrong
    // once the answer is rendered as "713 m" (T-156). Default stays unbounded
    // so the viewport path keeps its instant, good-enough answer.
    const last = await m.getLastKnownPositionAsync(lastKnown);
    // Only RETURN a usable cached region. A bogus one (`toRegion` → null) must
    // fall through to the fresh fix, not short-circuit it into "no location".
    const cached = last ? toRegion(last.coords) : null;
    if (cached) return cached;
  } catch {
    // Fall through to a fresh fix.
  }

  try {
    return await firstFixWithin(m, timeoutMs);
  } catch {
    return null;
  }
}

/**
 * The first usable fix within `timeoutMs`, or null.
 *
 * Watches rather than calling `getCurrentPositionAsync` because a watch is the
 * only CANCELLABLE one-shot in this API. `getCurrentPositionAsync` accepts no
 * signal, so racing it against a timer abandons the native call — it keeps
 * running for the life of the process, and it is trivially reachable: any user
 * indoors, in a tunnel, or on a simulator with no location set never gets a fix.
 * Beyond the leak, a still-pending Expo async call is destroyed against a dead
 * JS runtime on teardown, which is a native crash rather than an exception.
 */
function firstFixWithin(m: LocationModule, timeoutMs: number): Promise<Region | null> {
  return new Promise((resolve) => {
    let subscription: { remove: () => void } | null = null;
    let settled = false;

    const finish = (region: Region | null) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      subscription?.remove();
      resolve(region);
    };

    const timer = setTimeout(() => finish(null), timeoutMs);

    m.watchPositionAsync(
      { accuracy: m.Accuracy.Balanced },
      (location) => {
        // One bogus reading does not end the watch — keep listening for a good
        // fix until the timeout, rather than reporting "no location" because a
        // flaky provider emitted a single NaN.
        const region = toRegion(location.coords);
        if (region) finish(region);
      },
      // Watch-level failure (permission pulled mid-watch, provider died): give
      // up now instead of holding the caller until the timeout expires.
      () => finish(null),
    )
      .then((sub) => {
        subscription = sub;
        // Already finished before the subscription landed — remove it here or
        // it watches forever with nobody listening, which is the exact leak
        // this function exists to avoid.
        if (settled) sub.remove();
      })
      .catch(() => finish(null));
  });
}

function toRegion(coords: { latitude: number; longitude: number }): Region | null {
  // A bogus fix (NaN from a flaky provider) would centre the map on nowhere —
  // treat it as "no fix" rather than propagating it into the viewport.
  if (!Number.isFinite(coords.latitude) || !Number.isFinite(coords.longitude)) return null;
  return {
    latitude: coords.latitude,
    longitude: coords.longitude,
    latitudeDelta: USER_REGION_DELTA,
    longitudeDelta: USER_REGION_DELTA,
  };
}

/**
 * Open this app's OS settings page, where a permanently-denied permission can
 * be re-granted. `openSettings()` is cross-platform, so this is just the
 * swallow-the-rejection wrapper (same shape as `openExternal` in `linking.ts`)
 * — the hint text still tells the user where to go if the jump fails.
 */
export async function openLocationSettings(): Promise<void> {
  try {
    await Linking.openSettings();
  } catch {
    // Nothing actionable for the user beyond the tap doing nothing.
  }
}

/**
 * How stale a cached fix may be before a DISTANCE is measured from it. Two
 * minutes: long enough that reopening the map is instant, short enough that a
 * walk across town cannot be reported as "50 m".
 */
export const VIEWER_FIX_MAX_AGE_MS = 2 * 60 * 1000;

/**
 * How coarse a cached fix may be, in metres, for the same purpose. iOS with
 * Precise Location OFF returns a ~1–3 km reading, and rendering "713 m" from
 * one is a fabricated precision — the same class of wrong as a fabricated
 * "Closed". Past this, wait for a fresh fix instead.
 */
export const VIEWER_FIX_MAX_ACCURACY_M = 500;

/**
 * The viewer's position WITHOUT ever prompting, or null.
 *
 * Extracted because it existed twice: the redemption screen reads the fix this
 * way so that a customer at the counter is never blocked by a permission
 * dialog, and the map needs the identical rule so that a distance label never
 * costs the app its one location prompt. Two copies of "the position, if we may
 * already have it" is two places for the next change — coarse location, a
 * cached-fix fallback — to land in only one of.
 */
export async function positionIfGranted(timeoutMs: number = FIX_TIMEOUT_MS): Promise<Region | null> {
  if ((await getLocationPermission()).state !== 'granted') return null;

  return getUserRegion(timeoutMs, {
    maxAge: VIEWER_FIX_MAX_AGE_MS,
    requiredAccuracy: VIEWER_FIX_MAX_ACCURACY_M,
  });
}
