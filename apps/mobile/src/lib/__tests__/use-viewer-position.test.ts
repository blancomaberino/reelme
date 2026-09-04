import { renderHook, waitFor } from '@testing-library/react-native';
import * as Location from 'expo-location';

import { useViewerPosition } from '../use-viewer-position';

/**
 * T-156. This hook's whole contract is a NEGATIVE one: it supplies the point
 * distances are measured from, and it must never be the thing that asks for
 * location. iOS gives one prompt; spending it here — silently, so a distance
 * label can appear — would cost the map its "locate me" if the user says no.
 */
const perms = jest.mocked(Location.getForegroundPermissionsAsync);
const requestPerms = jest.mocked(Location.requestForegroundPermissionsAsync);
const lastKnown = jest.mocked(Location.getLastKnownPositionAsync);
const watchPos = jest.mocked(Location.watchPositionAsync);

const FIX = { latitude: -34.9011, longitude: -56.1645 };
const granted = { status: Location.PermissionStatus.GRANTED, canAskAgain: true } as never;
const undetermined = { status: Location.PermissionStatus.UNDETERMINED, canAskAgain: true } as never;
const denied = { status: Location.PermissionStatus.DENIED, canAskAgain: true } as never;

beforeEach(() => {
  jest.clearAllMocks();
  perms.mockResolvedValue(granted);
  requestPerms.mockResolvedValue(granted);
  lastKnown.mockResolvedValue({ coords: FIX } as never);
  watchPos.mockImplementation(async (_o, cb) => {
    (cb as (l: unknown) => void)({ coords: FIX });
    return { remove: jest.fn() } as never;
  });
});

it('yields the viewer point when permission is already granted', async () => {
  const { result } = renderHook(() => useViewerPosition());

  await waitFor(() => expect(result.current).toEqual(FIX));
  expect(requestPerms).not.toHaveBeenCalled();
});

it('NEVER prompts — an undetermined permission yields null, not a dialog', async () => {
  // The one that matters. `undetermined` is precisely the state where asking
  // would work, which is why a hook that forgot this rule would look correct.
  perms.mockResolvedValue(undetermined);

  const { result } = renderHook(() => useViewerPosition());

  await waitFor(() => expect(perms).toHaveBeenCalled());
  expect(requestPerms).not.toHaveBeenCalled();
  expect(lastKnown).not.toHaveBeenCalled();
  expect(result.current).toBeNull();
});

it('yields null when permission is denied', async () => {
  perms.mockResolvedValue(denied);

  const { result } = renderHook(() => useViewerPosition());

  await waitFor(() => expect(perms).toHaveBeenCalled());
  expect(result.current).toBeNull();
});

it('yields null when granted but no fix arrives', async () => {
  // Indoors, in a tunnel, or a simulator with no location set. Null, not a
  // guess — every field derived from it is then absent rather than faked.
  lastKnown.mockResolvedValue(null);
  watchPos.mockImplementation(async () => ({ remove: jest.fn() }) as never);

  const { result } = renderHook(() => useViewerPosition());

  await waitFor(() => expect(lastKnown).toHaveBeenCalled());
  expect(result.current).toBeNull();
});

it('does not set state after unmount', async () => {
  // A fix can take seconds; the map is a tab the user can leave immediately.
  let release: (v: unknown) => void = () => {};
  lastKnown.mockReturnValue(new Promise((r) => (release = r)) as never);
  const warn = jest.spyOn(console, 'error').mockImplementation(() => {});

  const { unmount } = renderHook(() => useViewerPosition());
  await waitFor(() => expect(lastKnown).toHaveBeenCalled());
  unmount();
  release({ coords: FIX });
  await Promise.resolve();

  expect(warn).not.toHaveBeenCalled();
  warn.mockRestore();
});
