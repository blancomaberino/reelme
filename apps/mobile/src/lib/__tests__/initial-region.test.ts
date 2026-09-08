import * as Location from 'expo-location';

import {
  CENTER_ON_VIEWER_RADIUS_M,
  DEFAULT_REGION,
  locateUser,
  regionFromParams,
  resolveInitialRegion,
  shouldCenterOnViewer,
  syncInitialRegion,
} from '../initial-region';

// T-100: where the map opens. The fallback chain has four rungs and a permission
// prompt in the middle of it, so every rung is pinned here — a regression that
// silently drops to DEFAULT_REGION is exactly the bug this task fixed.

const perms = jest.mocked(Location.getForegroundPermissionsAsync);
const requestPerms = jest.mocked(Location.requestForegroundPermissionsAsync);
const lastKnown = jest.mocked(Location.getLastKnownPositionAsync);
const watchPos = jest.mocked(Location.watchPositionAsync);
/**
 * The fresh-fix path WATCHES (the only cancellable one-shot — see location.ts),
 * so "the provider gave us X" is expressed as a watch that emits X, and "no fix"
 * as a watch that never calls back.
 */
const watchEmits = (coords: { latitude: number; longitude: number } | null) =>
  watchPos.mockImplementation(async (_o, cb) => {
    if (coords) (cb as (l: unknown) => void)({ coords });
    return { remove: jest.fn() } as never;
  });


const SAVED = { latitude: 51.5, longitude: -0.12, latitudeDelta: 0.05, longitudeDelta: 0.05 };

// Madrid — the fix jest.setup hands back by default.
const FIX_LAT = 40.4168;
const FIX_LNG = -3.7038;

// `as never` satisfies the mock's full LocationPermissionResponse type without
// restating fields these tests don't exercise.
const granted = { status: Location.PermissionStatus.GRANTED, canAskAgain: true } as never;
const undetermined = { status: Location.PermissionStatus.UNDETERMINED, canAskAgain: true } as never;
const deniedAskable = { status: Location.PermissionStatus.DENIED, canAskAgain: true } as never;
const deniedBlocked = { status: Location.PermissionStatus.DENIED, canAskAgain: false } as never;

beforeEach(() => {
  jest.clearAllMocks();
  perms.mockResolvedValue(granted);
  requestPerms.mockResolvedValue(granted);
  lastKnown.mockResolvedValue(null);
  watchEmits({ latitude: FIX_LAT, longitude: FIX_LNG });
});

describe('regionFromParams', () => {
  it('builds a region from a valid lat/lng pair', () => {
    expect(regionFromParams('40.4', '-3.7')).toEqual({
      latitude: 40.4,
      longitude: -3.7,
      latitudeDelta: 0.02,
      longitudeDelta: 0.02,
    });
  });

  it('rejects a lat-only push instead of centring on longitude 0', () => {
    // Number('') is 0 — the Gulf of Guinea bug this guard exists for.
    expect(regionFromParams('40.4', undefined)).toBeNull();
    expect(regionFromParams('40.4', '')).toBeNull();
  });

  it('rejects non-finite and out-of-range coordinates', () => {
    expect(regionFromParams('abc', '-3.7')).toBeNull();
    expect(regionFromParams('91', '-3.7')).toBeNull();
    expect(regionFromParams('40.4', '181')).toBeNull();
  });
});

describe('syncInitialRegion', () => {
  it('prefers a deep-link param over a saved viewport', () => {
    const result = syncInitialRegion({ lat: '10', lng: '20', saved: SAVED, hydrated: true });
    expect(result).toEqual({
      region: { latitude: 10, longitude: 20, latitudeDelta: 0.02, longitudeDelta: 0.02 },
      source: 'param',
      permissionBlocked: false,
    });
  });

  it('uses the saved viewport when there is no param', () => {
    expect(syncInitialRegion({ saved: SAVED, hydrated: true })).toEqual({
      region: SAVED,
      source: 'saved',
      permissionBlocked: false,
    });
  });

  it('does not trust a null saved viewport before hydration has run', () => {
    // hydrated=false means "haven't looked yet", NOT "nothing saved" — returning
    // null here is what makes the caller wait instead of hitting the GPS.
    expect(syncInitialRegion({ saved: null, hydrated: false })).toBeNull();
    expect(syncInitialRegion({ saved: SAVED, hydrated: false })).toBeNull();
  });

  it('returns null on a genuine first launch (hydrated, nothing saved)', () => {
    expect(syncInitialRegion({ saved: null, hydrated: true })).toBeNull();
  });
});

describe('resolveInitialRegion', () => {
  it('centres on the user on first launch, prompting for permission', async () => {
    perms.mockResolvedValue(undetermined);

    const result = await resolveInitialRegion({ saved: null });

    expect(requestPerms).toHaveBeenCalledTimes(1);
    expect(result.source).toBe('user');
    expect(result.region.latitude).toBe(FIX_LAT);
    expect(result.region.longitude).toBe(FIX_LNG);
    expect(result.permissionBlocked).toBe(false);
  });

  it('never re-prompts a permission the user already declined', async () => {
    perms.mockResolvedValue(deniedAskable);

    const result = await resolveInitialRegion({ saved: null });

    expect(requestPerms).not.toHaveBeenCalled();
    expect(result.region).toEqual(DEFAULT_REGION);
    expect(result.source).toBe('default');
    // Askable-again is not "blocked" — no Settings nag for a one-off dismissal.
    expect(result.permissionBlocked).toBe(false);
  });

  it('flags a permanently-blocked permission so the screen can point at Settings', async () => {
    perms.mockResolvedValue(deniedBlocked);

    const result = await resolveInitialRegion({ saved: null });

    expect(result.source).toBe('default');
    expect(result.permissionBlocked).toBe(true);
  });

  it('falls back to the default region when permission is granted but no fix arrives', async () => {
    lastKnown.mockResolvedValue(null);
    watchEmits(null);

    const result = await resolveInitialRegion({ saved: null });

    expect(result.region).toEqual(DEFAULT_REGION);
    expect(result.source).toBe('default');
    expect(result.permissionBlocked).toBe(false);
  });

  it('falls back when the location provider throws', async () => {
    lastKnown.mockRejectedValue(new Error('no provider'));
    watchPos.mockRejectedValue(new Error('no provider'));

    const result = await resolveInitialRegion({ saved: null });

    expect(result.source).toBe('default');
  });

  it('treats a non-finite fix as no fix rather than centring on nowhere', async () => {
    watchEmits({ latitude: NaN, longitude: 0 });

    const result = await resolveInitialRegion({ saved: null });

    expect(result.region).toEqual(DEFAULT_REGION);
  });

  it('prefers the OS cached fix over paying for a fresh one', async () => {
    lastKnown.mockResolvedValue({ coords: { latitude: 1.5, longitude: 2.5 } } as never);

    const result = await resolveInitialRegion({ saved: null });

    expect(result.region.latitude).toBe(1.5);
    expect(watchPos).not.toHaveBeenCalled();
  });

  it('takes the saved viewport without touching location at all', async () => {
    const result = await resolveInitialRegion({ saved: SAVED });

    expect(result).toEqual({ region: SAVED, source: 'saved', permissionBlocked: false });
    expect(perms).not.toHaveBeenCalled();
    expect(watchPos).not.toHaveBeenCalled();
  });

  it('lets a deep-link param win over both the saved viewport and location', async () => {
    const result = await resolveInitialRegion({ lat: '10', lng: '20', saved: SAVED });

    expect(result.source).toBe('param');
    expect(result.region.latitude).toBe(10);
    expect(perms).not.toHaveBeenCalled();
  });
});

describe('locateUser', () => {
  it('returns the user region when permission is already granted', async () => {
    const result = await locateUser();

    expect(result).toEqual({
      ok: true,
      region: { latitude: FIX_LAT, longitude: FIX_LNG, latitudeDelta: 0.02, longitudeDelta: 0.02 },
    });
    // Already granted — no need to re-prompt.
    expect(requestPerms).not.toHaveBeenCalled();
  });

  it('prompts when the permission is undetermined (the tap IS the consent moment)', async () => {
    perms.mockResolvedValue(undetermined);

    const result = await locateUser();

    expect(requestPerms).toHaveBeenCalledTimes(1);
    expect(result.ok).toBe(true);
  });

  it('re-prompts a previously-dismissed permission and reports a fresh refusal', async () => {
    perms.mockResolvedValue(deniedAskable);
    requestPerms.mockResolvedValue(deniedAskable);

    // Unlike the mount-time chain, an explicit tap DOES re-ask — the user just
    // asked us to.
    expect(await locateUser()).toEqual({ ok: false, reason: 'denied' });
    expect(requestPerms).toHaveBeenCalledTimes(1);
  });

  it('reports "blocked" when the OS will not prompt again', async () => {
    perms.mockResolvedValue(deniedBlocked);
    requestPerms.mockResolvedValue(deniedBlocked);

    expect(await locateUser()).toEqual({ ok: false, reason: 'blocked' });
  });

  it('reports "unavailable" when permission is granted but no fix arrives', async () => {
    lastKnown.mockResolvedValue(null);
    watchEmits(null);

    expect(await locateUser()).toEqual({ ok: false, reason: 'unavailable' });
  });
});

describe('shouldCenterOnViewer', () => {
  // T-156: the map re-frames onto the viewer when they are near their own
  // places, and leaves them where they were when they are not. Both branches,
  // because the failure modes are opposite and equally bad: never re-framing
  // strands a user who moved across town on last night's viewport, and always
  // re-framing yanks a traveller onto an empty map 200 km from every pin.
  const anchor = { latitude: -34.9011, longitude: -56.1645, latitudeDelta: 0.05, longitudeDelta: 0.05 };

  /** A point `metres` due NORTH of the anchor — latitude only, so no cos() term. */
  const northOf = (metres: number) => ({
    latitude: anchor.latitude + metres / 111_320,
    longitude: anchor.longitude,
  });

  it('centers on a viewer inside the radius (they moved across town)', () => {
    expect(shouldCenterOnViewer({ viewer: northOf(4_000), anchor, source: 'saved' })).toBe(true);
  });

  it('keeps the saved viewport for a viewer outside it (they travelled)', () => {
    expect(shouldCenterOnViewer({ viewer: northOf(220_000), anchor, source: 'saved' })).toBe(false);
  });

  it('flips just inside and just outside the radius', () => {
    // Pins the decision to the CONSTANT rather than to a hard-coded 30 km, so a
    // radius someone changes has to change these too. Fifty metres either side,
    // not the radius exactly: `northOf` is a flat-earth step and the code
    // measures a great circle, so an exact-boundary case would be deciding a
    // tie between two different approximations — a flaky test dressed as a
    // precise one. Which side of `<=` the exact value falls on is not a
    // behaviour anybody can observe.
    expect(shouldCenterOnViewer({ viewer: northOf(CENTER_ON_VIEWER_RADIUS_M - 50), anchor, source: 'saved' }))
      .toBe(true);
    expect(shouldCenterOnViewer({ viewer: northOf(CENTER_ON_VIEWER_RADIUS_M + 50), anchor, source: 'saved' }))
      .toBe(false);
  });

  it('never overrides a rung that already knows better', () => {
    const viewer = northOf(1_000);
    // `param` is an explicit "show me THIS" push; `user` is already the viewer;
    // `default` has no anchor worth being near. Each must stay put even though
    // the viewer is a kilometre away and would otherwise qualify.
    expect(shouldCenterOnViewer({ viewer, anchor, source: 'param' })).toBe(false);
    expect(shouldCenterOnViewer({ viewer, anchor, source: 'user' })).toBe(false);
    expect(shouldCenterOnViewer({ viewer, anchor, source: 'default' })).toBe(false);
  });

  it('does nothing without a fix or without an anchor', () => {
    expect(shouldCenterOnViewer({ viewer: null, anchor, source: 'saved' })).toBe(false);
    expect(shouldCenterOnViewer({ viewer: northOf(100), anchor: null, source: 'saved' })).toBe(false);
  });
});
