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
const watchPos = jest.mocked(Location.watchPositionAsync);

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
  /** A watch that hands back a subscription but never delivers a fix. */
  const silentWatch = () => {
    const remove = jest.fn();
    watchPos.mockImplementationOnce(async () => ({ remove }) as never);
    return remove;
  };

  it('uses the OS cached fix without paying for a fresh one', async () => {
    lastKnown.mockResolvedValueOnce({ coords: { latitude: 10, longitude: 20 } } as never);

    expect(await getUserRegion()).toEqual({
      latitude: 10,
      longitude: 20,
      latitudeDelta: 0.02,
      longitudeDelta: 0.02,
    });
    expect(watchPos).not.toHaveBeenCalled();
  });

  it('falls through to a fresh fix when the cache is empty or throws', async () => {
    lastKnown.mockRejectedValueOnce(new Error('no cache'));
    watchPos.mockImplementationOnce(async (_o, cb) => {
      (cb as (l: unknown) => void)({ coords: { latitude: 1, longitude: 2 } });
      return { remove: jest.fn() } as never;
    });

    expect(await getUserRegion()).toMatchObject({ latitude: 1, longitude: 2 });
  });

  it('returns null when both the cache and a fresh fix fail', async () => {
    lastKnown.mockResolvedValueOnce(null);
    watchPos.mockRejectedValueOnce(new Error('location unavailable'));

    expect(await getUserRegion()).toBeNull();
  });

  it('falls through to a fresh fix when the CACHED fix is bogus', async () => {
    // A stale NaN from a flaky provider must not short-circuit the fallback into
    // "no location" — there is still a perfectly good fresh fix to ask for.
    lastKnown.mockResolvedValueOnce({ coords: { latitude: NaN, longitude: 20 } } as never);
    watchPos.mockImplementationOnce(async (_o, cb) => {
      (cb as (l: unknown) => void)({ coords: { latitude: 3, longitude: 4 } });
      return { remove: jest.fn() } as never;
    });

    expect(await getUserRegion()).toMatchObject({ latitude: 3, longitude: 4 });
  });

  it('keeps watching past a bogus reading instead of reporting no location', async () => {
    // A single NaN frame is not "no fix" — the watch is still live and the very
    // next reading may be good. Ending on the first bad frame would strand the
    // map on its fallback city for a user whose provider warmed up slowly.
    lastKnown.mockResolvedValueOnce(null);
    const remove = jest.fn();
    watchPos.mockImplementationOnce(async (_o, cb) => {
      const emit = cb as (l: unknown) => void;
      emit({ coords: { latitude: NaN, longitude: 20 } });
      emit({ coords: { latitude: 7, longitude: 8 } });
      return { remove } as never;
    });

    expect(await getUserRegion()).toMatchObject({ latitude: 7, longitude: 8 });
  });

  it('refuses a FRESH fix coarser than the caller asked for, and keeps watching for a good one', async () => {
    // `requiredAccuracy` was passed only to `getLastKnownPositionAsync`, which
    // is the one place Expo enforces it. So on iOS with Precise Location off —
    // the exact case the bound exists for — the coarse CACHED reading was
    // refused, an equally coarse FRESH one was fetched at radio cost, and the
    // caller rendered "713 m" off a ±2 km guess anyway. The guard cost battery
    // and prevented nothing.
    lastKnown.mockResolvedValueOnce(null);
    const remove = jest.fn();
    watchPos.mockImplementationOnce(async (_o, cb) => {
      const emit = cb as (l: unknown) => void;
      emit({ coords: { latitude: 1, longitude: 2, accuracy: 2_000 } }); // Precise Location off.
      emit({ coords: { latitude: 7, longitude: 8, accuracy: 15 } }); // The watch narrows.
      return { remove } as never;
    });

    expect(await getUserRegion(5_000, { requiredAccuracy: 500 }))
      .toMatchObject({ latitude: 7, longitude: 8 });
  });

  it('reports no position at all when the fix never gets precise enough', async () => {
    // Null, not the coarse reading. A distance the caller cannot stand behind is
    // worse than no distance: the sheet renders nothing rather than a number.
    jest.useFakeTimers();
    lastKnown.mockResolvedValueOnce(null);
    watchPos.mockImplementationOnce(async (_o, cb) => {
      (cb as (l: unknown) => void)({ coords: { latitude: 1, longitude: 2, accuracy: 3_000 } });
      return { remove: jest.fn() } as never;
    });

    const pending = getUserRegion(5_000, { requiredAccuracy: 500 });
    await jest.advanceTimersByTimeAsync(5_000);

    expect(await pending).toBeNull();
    jest.useRealTimers();
  });

  it('accepts a fix whose accuracy the provider does not report', async () => {
    // `LocationObjectCoords.accuracy` is `number | null`, and some Android
    // providers genuinely leave it null. Refusing those would deny distances to
    // real devices to guard against a hypothetical — the case the bound exists
    // for reports a number, a large one.
    lastKnown.mockResolvedValueOnce(null);
    watchPos.mockImplementationOnce(async (_o, cb) => {
      (cb as (l: unknown) => void)({ coords: { latitude: 7, longitude: 8, accuracy: null } });
      return { remove: jest.fn() } as never;
    });

    expect(await getUserRegion(5_000, { requiredAccuracy: 500 }))
      .toMatchObject({ latitude: 7, longitude: 8 });
  });

  it('rejects a non-finite fix rather than centring the map on nowhere', async () => {
    jest.useFakeTimers();
    lastKnown.mockResolvedValueOnce({ coords: { latitude: NaN, longitude: 20 } } as never);
    watchPos.mockImplementationOnce(async (_o, cb) => {
      (cb as (l: unknown) => void)({ coords: { latitude: 10, longitude: Infinity } });
      return { remove: jest.fn() } as never;
    });

    const pending = getUserRegion(1_000);
    await jest.advanceTimersByTimeAsync(1_000);

    expect(await pending).toBeNull();
    jest.useRealTimers();
  });

  it('gives up on a hanging GPS instead of blocking the map forever', async () => {
    jest.useFakeTimers();
    lastKnown.mockResolvedValueOnce(null);
    silentWatch(); // the real-world tunnel/indoor case — a fix that never arrives

    const pending = getUserRegion(1_000);
    await jest.advanceTimersByTimeAsync(1_000);

    expect(await pending).toBeNull();
    jest.useRealTimers();
  });

  // --- the leak this change exists to close --------------------------------
  // getCurrentPositionAsync takes no cancellation signal, so the previous
  // timeout raced it and walked away, leaving a native call running for the life
  // of the process. Every assertion below is about the watch being TORN DOWN.

  it('removes the watch when it times out without a fix', async () => {
    jest.useFakeTimers();
    lastKnown.mockResolvedValueOnce(null);
    const remove = silentWatch();

    const pending = getUserRegion(1_000);
    await jest.advanceTimersByTimeAsync(1_000);
    await pending;

    expect(remove).toHaveBeenCalledTimes(1);
    jest.useRealTimers();
  });

  it('removes the watch once it has its fix', async () => {
    lastKnown.mockResolvedValueOnce(null);
    const remove = jest.fn();
    watchPos.mockImplementationOnce(async (_o, cb) => {
      (cb as (l: unknown) => void)({ coords: { latitude: 5, longitude: 6 } });
      return { remove } as never;
    });

    await getUserRegion();

    // One fix is all we want; leaving it running would keep the GPS warm for the
    // rest of the session.
    expect(remove).toHaveBeenCalledTimes(1);
  });

  it('removes a subscription that arrives AFTER the timeout fired', async () => {
    // The nastiest ordering: watchPositionAsync is itself async, so the timeout
    // can win before there is anything to cancel. Nothing would ever remove this
    // one, and it is the case a naive fix forgets.
    jest.useFakeTimers();
    lastKnown.mockResolvedValueOnce(null);
    const remove = jest.fn();
    let handOverSubscription: () => void = () => {};
    watchPos.mockImplementationOnce(
      () => new Promise((res) => { handOverSubscription = () => res({ remove } as never); }),
    );

    const pending = getUserRegion(1_000);
    await jest.advanceTimersByTimeAsync(1_000);
    expect(await pending).toBeNull();
    expect(remove).not.toHaveBeenCalled(); // nothing to remove yet

    handOverSubscription();
    await Promise.resolve();
    await Promise.resolve();

    expect(remove).toHaveBeenCalledTimes(1);
    jest.useRealTimers();
  });

  it('degrades to null when the module throws synchronously rather than propagating', async () => {
    // This whole file's contract is "never throw" — a caller that has to
    // try/catch a location lookup will forget, and the map goes down with it.
    // A malformed/partial native module (a watch that isn't callable) is the
    // realistic way that happens, and it throws INSIDE the promise executor.
    lastKnown.mockResolvedValueOnce(null);
    watchPos.mockImplementationOnce(() => {
      throw new TypeError('watchPositionAsync is not a function');
    });

    await expect(getUserRegion()).resolves.toBeNull();
  });

  it('gives up immediately when the watch itself errors, without waiting out the timeout', async () => {
    jest.useFakeTimers();
    lastKnown.mockResolvedValueOnce(null);
    watchPos.mockImplementationOnce(async (_o, _cb, onError) => {
      (onError as () => void)();
      return { remove: jest.fn() } as never;
    });

    // Resolves without the timer ever running — permission pulled mid-watch
    // should not hold the map's loading gate for the full budget.
    expect(await getUserRegion(60_000)).toBeNull();
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
