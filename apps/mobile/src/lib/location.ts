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
export async function getUserRegion(timeoutMs: number = FIX_TIMEOUT_MS): Promise<Region | null> {
  const m = locationModule();
  if (!m) return null;

  try {
    const last = await m.getLastKnownPositionAsync();
    if (last) return toRegion(last.coords);
  } catch {
    // Fall through to a fresh fix.
  }

  try {
    const fresh = await withTimeout(
      m.getCurrentPositionAsync({ accuracy: m.Accuracy.Balanced }),
      timeoutMs,
    );
    return fresh ? toRegion(fresh.coords) : null;
  } catch {
    return null;
  }
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

/** Resolve to null instead of hanging forever. */
function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | null> {
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(null), ms);
    promise
      .then((value) => {
        clearTimeout(timer);
        resolve(value);
      })
      .catch(() => {
        clearTimeout(timer);
        resolve(null);
      });
  });
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
