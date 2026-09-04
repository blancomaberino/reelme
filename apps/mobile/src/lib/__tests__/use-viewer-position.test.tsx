import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react-native';
import { createElement, type ReactNode } from 'react';
import { AppState } from 'react-native';
import * as Location from 'expo-location';

import { useRefreshViewerPosition, useViewerPosition } from '@/lib/use-viewer-position';

/**
 * T-156. This hook's contract is mostly a NEGATIVE one: it supplies the point
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

let qc: QueryClient;
function wrapper({ children }: { children: ReactNode }) {
  return createElement(QueryClientProvider, { client: qc }, children);
}

beforeEach(() => {
  jest.clearAllMocks();
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  perms.mockResolvedValue(granted);
  requestPerms.mockResolvedValue(granted);
  lastKnown.mockResolvedValue({ coords: FIX } as never);
  watchPos.mockImplementation(async (_o, cb) => {
    (cb as (l: unknown) => void)({ coords: FIX });
    return { remove: jest.fn() } as never;
  });
});

afterEach(() => qc.clear());

it('yields the viewer point when permission is already granted', async () => {
  const { result } = renderHook(() => useViewerPosition(), { wrapper });

  await waitFor(() => expect(result.current).toEqual(FIX));
  expect(requestPerms).not.toHaveBeenCalled();
});

it('NEVER prompts — an undetermined permission yields null, not a dialog', async () => {
  // The one that matters. `undetermined` is precisely the state where asking
  // WOULD work, which is why a hook that forgot this rule would look correct
  // in every other test.
  perms.mockResolvedValue(undetermined);

  const { result } = renderHook(() => useViewerPosition(), { wrapper });

  await waitFor(() => expect(perms).toHaveBeenCalled());
  expect(requestPerms).not.toHaveBeenCalled();
  expect(lastKnown).not.toHaveBeenCalled();
  expect(result.current).toBeNull();
});

it('yields null when permission is denied', async () => {
  perms.mockResolvedValue(denied);

  const { result } = renderHook(() => useViewerPosition(), { wrapper });

  await waitFor(() => expect(perms).toHaveBeenCalled());
  expect(result.current).toBeNull();
});

it('yields null when granted but no usable fix arrives', async () => {
  // Indoors, in a tunnel, or a simulator with no location set. Null, not a
  // guess — every field derived from it is then absent rather than faked.
  lastKnown.mockResolvedValue(null);
  watchPos.mockImplementation(async () => ({ remove: jest.fn() }) as never);

  const { result } = renderHook(() => useViewerPosition(), { wrapper });

  await waitFor(() => expect(lastKnown).toHaveBeenCalled());
  expect(result.current).toBeNull();
});

it('refuses a cached fix that is stale or coarse, rather than measuring from it', async () => {
  // `getLastKnownPositionAsync` unbounded will hand back a reading from another
  // city hours ago — free when the answer only has to frame a viewport, wrong
  // once it is rendered as "713 m". iOS with Precise Location off returns a
  // 1–3 km fix, and a distance label at 11 m precision on top of that is a
  // fabricated precision.
  renderHook(() => useViewerPosition(), { wrapper });

  await waitFor(() => expect(lastKnown).toHaveBeenCalled());
  const options = lastKnown.mock.calls[0][0];
  expect(options?.maxAge).toBeGreaterThan(0);
  expect(options?.requiredAccuracy).toBeGreaterThan(0);
});

it('re-asks when the app returns to the foreground', async () => {
  // A permission granted in Settings is invisible to the app until it comes
  // back; that transition is the only signal there is. Without this, a user who
  // enables location in Settings sees no distances until they force-quit.
  perms.mockResolvedValue(denied);
  const { result } = renderHook(() => useViewerPosition(), { wrapper });
  await waitFor(() => expect(result.current).toBeNull());

  perms.mockResolvedValue(granted);
  const handler = jest.mocked(AppState.addEventListener).mock.calls.at(-1)?.[1] as (s: string) => void;
  handler('active');

  await waitFor(() => expect(result.current).toEqual(FIX));
});

it('does NOT spin the radio on a foreground when the fix it holds is still fresh', async () => {
  // The other half of the rule above, and the reason the foreground handler is
  // not an unconditional invalidate. Past VIEWER_FIX_MAX_AGE_MS
  // `getLastKnownPositionAsync` refuses the cached reading and a GPS watch runs
  // for up to five seconds — so re-asking on EVERY resume spent radio and
  // battery to re-learn a position that had not moved. Re-ask only when the
  // answer can differ: we hold none, or the one we hold is older than we serve.
  const { result } = renderHook(() => useViewerPosition(), { wrapper });
  await waitFor(() => expect(result.current).toEqual(FIX));
  const callsBefore = lastKnown.mock.calls.length;

  const handler = jest.mocked(AppState.addEventListener).mock.calls.at(-1)?.[1] as (s: string) => void;
  handler('active');

  // Give an invalidate a chance to land before asserting it did not happen.
  await new Promise((r) => setTimeout(r, 20));
  expect(lastKnown).toHaveBeenCalledTimes(callsBefore);
  expect(result.current).toEqual(FIX);
});

it('re-asks when a control that owns its prompt reports a grant', async () => {
  // "Locate me" prompts; granting there is a new answer to the question this
  // hook asked at mount and was told "not allowed" to. Without the refresh the
  // map flies to the user and every pin stays distance-less until a restart.
  perms.mockResolvedValue(denied);
  const { result } = renderHook(
    () => ({ point: useViewerPosition(), refresh: useRefreshViewerPosition() }),
    { wrapper },
  );
  await waitFor(() => expect(result.current.point).toBeNull());

  perms.mockResolvedValue(granted);
  result.current.refresh();

  await waitFor(() => expect(result.current.point).toEqual(FIX));
});
