// Persisted map viewport (T-100). The map is the app's home screen, so where it
// opens should be where you left it — not a hardcoded city. Stored in
// SecureStore for the same reason the locale is (already a dependency for the
// auth token; no extra native module / AsyncStorage rebuild).
import * as SecureStore from 'expo-secure-store';

import type { Region } from './geo';

const VIEWPORT_KEY = 'map_viewport';

/**
 * Deltas outside this range are corrupt/absurd — reject rather than restore.
 * The floor is deliberately far below the tightest viewport a pinch can reach
 * (max native zoom is ~5e-5 latitudeDelta): this guard exists to catch a zero
 * or a garbage value, not to second-guess a legitimately deep zoom.
 */
const MIN_DELTA = 1e-9;
const MAX_DELTA = 180;

function isValidRegion(value: unknown): value is Region {
  if (typeof value !== 'object' || value === null) return false;
  const r = value as Record<string, unknown>;
  const nums = [r.latitude, r.longitude, r.latitudeDelta, r.longitudeDelta];
  if (!nums.every((n) => typeof n === 'number' && Number.isFinite(n))) return false;
  const { latitude, longitude, latitudeDelta, longitudeDelta } = r as Record<string, number>;
  return (
    latitude >= -90 &&
    latitude <= 90 &&
    longitude >= -180 &&
    longitude <= 180 &&
    latitudeDelta >= MIN_DELTA &&
    latitudeDelta <= MAX_DELTA &&
    longitudeDelta >= MIN_DELTA &&
    longitudeDelta <= MAX_DELTA
  );
}

/**
 * The last viewport the user settled on, or null when there is none / it is
 * unreadable. Never throws: a corrupt or hand-edited value degrades to "no
 * saved viewport" so the caller just falls through to its next option.
 */
export async function loadSavedViewport(): Promise<Region | null> {
  try {
    const raw = await SecureStore.getItemAsync(VIEWPORT_KEY);
    if (!raw) return null;
    const parsed: unknown = JSON.parse(raw);
    return isValidRegion(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

/**
 * Every MUTATION runs through one chain so the store lands in CALL order.
 * `saveViewport` is fire-and-forget and fires on every map settle, so without
 * this a write still in flight at sign-out can resolve AFTER the delete and put
 * the key back — handing the next person on a shared device the previous user's
 * last map position. A viewport is coarse location data, so that ordering is a
 * privacy property, not a nicety.
 *
 * Reads stay OFF the chain deliberately: queueing them would park boot
 * hydration behind a pending write and still wouldn't help, since a read
 * enqueued before a clear reads the pre-clear value either way. The store's
 * generation guard is what makes a late-arriving hydrate lose to a clear.
 */
let queue: Promise<unknown> = Promise.resolve();

function enqueue<T>(op: () => Promise<T>): Promise<T> {
  const run = queue.then(op);
  // The tail is always a RESOLVED promise, so one failed keychain call can
  // never wedge every later write behind it.
  queue = run.catch(() => undefined);
  return run;
}

/**
 * Persist a settled viewport. Fire-and-forget: this runs on every map settle,
 * and a failed write is not worth interrupting panning for.
 */
export function saveViewport(region: Region): void {
  if (!isValidRegion(region)) return;
  void enqueue(() => SecureStore.setItemAsync(VIEWPORT_KEY, JSON.stringify(region))).catch(() => {
    // Best-effort — the map still works, it just won't restore next launch.
  });
}

/** Drop the saved viewport (used by tests; also the natural sign-out hook). */
export async function clearSavedViewport(): Promise<void> {
  try {
    await enqueue(() => SecureStore.deleteItemAsync(VIEWPORT_KEY));
  } catch {
    // Nothing to do.
  }
}
