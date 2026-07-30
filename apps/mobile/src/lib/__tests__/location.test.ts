import * as Location from 'expo-location';
import { Linking } from 'react-native';

import {
  getLocationPermission,
  getUserRegion,
  openLocationSettings,
  requestLocationPermission,
} from '../location';

// T-100: the expo-location wrapper. The interesting cases are all failures —
// no native module (Expo Go / web), a throwing provider, a hanging GPS. Every
// one must degrade quietly, because the map has a perfectly good fallback and
// an exception here would take the whole screen down.

const perms = jest.mocked(Location.getForegroundPermissionsAsync);
const requestPerms = jest.mocked(Location.requestForegroundPermissionsAsync);
const lastKnown = jest.mocked(Location.getLastKnownPositionAsync);
const currentPos = jest.mocked(Location.getCurrentPositionAsync);

beforeEach(() => {
  jest.clearAllMocks();
  jest.useRealTimers();
});

describe('permission helpers', () => {
  it('maps each OS status onto the three-state enum', async () => {
    perms.mockResolvedValueOnce({ status: Location.PermissionStatus.GRANTED, canAskAgain: true } as never);
    expect(await getLocationPermission()).toEqual({ state: 'granted', canAskAgain: true });

    perms.mockResolvedValueOnce({ status: Location.PermissionStatus.DENIED, canAskAgain: false } as never);
    expect(await getLocationPermission()).toEqual({ state: 'denied', canAskAgain: false });

    perms.mockResolvedValueOnce({
      status: Location.PermissionStatus.UNDETERMINED,
      canAskAgain: true,
    } as never);
    expect(await getLocationPermission()).toEqual({ state: 'undetermined', canAskAgain: true });
  });

  it('treats a throwing permission call as permanently denied', async () => {
    perms.mockRejectedValueOnce(new Error('boom'));
    expect(await getLocationPermission()).toEqual({ state: 'denied', canAskAgain: false });

    requestPerms.mockRejectedValueOnce(new Error('boom'));
    expect(await requestLocationPermission()).toEqual({ state: 'denied', canAskAgain: false });
  });
});

describe('missing native module', () => {
  // The real-world failure this guard exists for: a dev client whose native
  // binary predates the expo-location dependency (also Expo Go, also web).
  // expo-location resolves its native module at IMPORT time, so a top-level
  // import would throw and take the whole map screen down — which is exactly
  // what happened on device before this was made lazy.
  beforeEach(() => {
    jest.resetModules();
    jest.doMock('expo-location', () => {
      throw new Error("Cannot find native module 'ExpoLocation'");
    });
  });

  afterEach(() => {
    jest.dontMock('expo-location');
    jest.resetModules();
  });

  function freshModule(): typeof import('../location') {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const mod = require('../location') as typeof import('../location');
    mod.__resetLocationModule();
    return mod;
  }

  it('loads without throwing even though expo-location does', () => {
    // If this throws, the map screen white-screens on a stale dev client.
    expect(() => freshModule()).not.toThrow();
  });

  it('reports permission as denied-and-unaskable rather than crashing', async () => {
    const { getLocationPermission: get, requestLocationPermission: req } = freshModule();

    expect(await get()).toEqual({ state: 'denied', canAskAgain: false });
    expect(await req()).toEqual({ state: 'denied', canAskAgain: false });
  });

  it('reports no user region rather than crashing', async () => {
    const { getUserRegion: get } = freshModule();

    expect(await get()).toBeNull();
  });
});

describe('getUserRegion', () => {
  it('uses the OS cached fix without paying for a fresh one', async () => {
    lastKnown.mockResolvedValueOnce({ coords: { latitude: 10, longitude: 20 } } as never);

    expect(await getUserRegion()).toEqual({
      latitude: 10,
      longitude: 20,
      latitudeDelta: 0.02,
      longitudeDelta: 0.02,
    });
    expect(currentPos).not.toHaveBeenCalled();
  });

  it('falls through to a fresh fix when the cache is empty or throws', async () => {
    lastKnown.mockRejectedValueOnce(new Error('no cache'));
    currentPos.mockResolvedValueOnce({ coords: { latitude: 1, longitude: 2 } } as never);

    expect(await getUserRegion()).toMatchObject({ latitude: 1, longitude: 2 });
  });

  it('returns null when both the cache and a fresh fix fail', async () => {
    lastKnown.mockResolvedValueOnce(null);
    currentPos.mockRejectedValueOnce(new Error('location unavailable'));

    expect(await getUserRegion()).toBeNull();
  });

  it('falls through to a fresh fix when the CACHED fix is bogus', async () => {
    // A stale NaN from a flaky provider must not short-circuit the fallback into
    // "no location" — there is still a perfectly good fresh fix to ask for.
    lastKnown.mockResolvedValueOnce({ coords: { latitude: NaN, longitude: 20 } } as never);
    currentPos.mockResolvedValueOnce({ coords: { latitude: 3, longitude: 4 } } as never);

    expect(await getUserRegion()).toMatchObject({ latitude: 3, longitude: 4 });
  });

  it('rejects a non-finite fix rather than centring the map on nowhere', async () => {
    lastKnown.mockResolvedValueOnce({ coords: { latitude: NaN, longitude: 20 } } as never);
    currentPos.mockResolvedValueOnce({ coords: { latitude: 10, longitude: Infinity } } as never);

    expect(await getUserRegion()).toBeNull();
  });

  it('gives up on a hanging GPS instead of blocking the map forever', async () => {
    jest.useFakeTimers();
    lastKnown.mockResolvedValueOnce(null);
    // A fix that never arrives — the real-world tunnel/indoor case.
    currentPos.mockReturnValueOnce(new Promise(() => {}) as never);

    const pending = getUserRegion(1_000);
    await jest.advanceTimersByTimeAsync(1_000);

    expect(await pending).toBeNull();
    jest.useRealTimers();
  });
});

describe('openLocationSettings', () => {
  it('opens the OS settings page', async () => {
    const openSettings = jest.spyOn(Linking, 'openSettings').mockResolvedValue(undefined);

    await openLocationSettings();

    expect(openSettings).toHaveBeenCalled();
    openSettings.mockRestore();
  });

  it('swallows a rejection rather than surfacing an unhandled error', async () => {
    const openSettings = jest.spyOn(Linking, 'openSettings').mockRejectedValue(new Error('nope'));

    await expect(openLocationSettings()).resolves.toBeUndefined();

    openSettings.mockRestore();
  });
});
