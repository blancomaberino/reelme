import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { Alert, Linking } from 'react-native';
import * as Location from 'expo-location';

import type { MapData } from '@/api/hooks/useMapPlaces';
import type { MapFilters } from '@/api/keys';
import { DEFAULT_REGION } from '@/lib/initial-region';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';
import { useViewportStore } from '@/stores/viewport';

import MapScreen from '../map';

import { mockRouter } from '../../../jest.setup';

// T-100: the map's location behaviour at the screen level — where it opens, the
// "locate me" control, the blocked-permission hint, and viewport persistence.
// The pin/marker/filter behaviour lives in map.test.tsx.

const { __animateToRegion: animateToRegion } = jest.requireMock('react-native-maps') as {
  __animateToRegion: jest.Mock;
};

const mapData: { current: MapData } = { current: { pins: [], clusters: [], truncated: false } };
jest.mock('@/api/hooks/useMapPlaces', () => ({
  useMapPlaces: (_region: unknown, _filters: MapFilters) => ({
    data: { ...mapData.current },
    isFetching: false,
    isSuccess: true,
  }),
}));
jest.mock('@/api/hooks/useTags', () => ({
  useTagCatalog: () => ({ data: [] }),
  useMyPlacesTags: () => ({ data: [] }),
  useMapTagCatalog: () => [],
}));
jest.mock('@/api/hooks/usePaymentCards', () => ({ usePaymentCards: () => ({ data: [] }) }));
jest.mock('@/api/hooks/useLists', () => ({
  useListMembership: () => ({ remove: { mutate: jest.fn() }, add: { mutate: jest.fn() } }),
}));
jest.mock('@/components/map/quick-share', () => ({ QuickShareModal: () => null }));
// The viewer point (T-156) is a SECOND, silent reader of the location
// permission, for distances rather than for the viewport. Mocked here so this
// file keeps testing what it says it does — where the map OPENS, and the locate
// control — instead of counting GPS calls it did not make. Its own contract
// (never prompts; null unless already granted) is pinned in
// src/lib/__tests__/use-viewer-position.test.ts.
const mockViewer: { current: { latitude: number; longitude: number } | null } = { current: null };
jest.mock('@/lib/use-viewer-position', () => ({
  useViewerPosition: () => mockViewer.current,
}));

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


const FIX = { latitude: 40.4168, longitude: -3.7038 };
const SAVED = { latitude: 51.5, longitude: -0.12, latitudeDelta: 0.05, longitudeDelta: 0.05 };

const granted = { status: Location.PermissionStatus.GRANTED, canAskAgain: true } as never;
const undetermined = { status: Location.PermissionStatus.UNDETERMINED, canAskAgain: true } as never;
const deniedBlocked = { status: Location.PermissionStatus.DENIED, canAskAgain: false } as never;

beforeEach(() => {
  jest.clearAllMocks();
  jest.useRealTimers();
  mapData.current = { pins: [], clusters: [], truncated: false };
  useMapStore.setState({
    selected: null,
    filters: { cuisine: null, price_range: null, tags: [], list: null, filter: null },
  });
  useSessionStore.setState({ user: null, status: 'guest' });
  useSettingsStore.setState({ locale: 'en' });
  useViewportStore.setState({ saved: null, hydrated: true });
  mockRouter.params = {};
  animateToRegion.mockClear();
  mockViewer.current = null;
  perms.mockResolvedValue(granted);
  requestPerms.mockResolvedValue(granted);
  lastKnown.mockResolvedValue(null);
  watchEmits(FIX);
});

describe('opening viewport', () => {
  it('paints immediately from a remembered viewport, without touching location', async () => {
    useViewportStore.setState({ saved: SAVED, hydrated: true });

    render(<MapScreen />);

    // No loading gate for a returning user — the map is there on first render.
    expect(screen.getByTestId('MapView')).toBeOnTheScreen();
    expect(screen.queryByText('Finding your location…')).toBeNull();
    expect(perms).not.toHaveBeenCalled();
    expect(screen.getByTestId('MapView').props.initialRegion).toEqual(SAVED);
  });

  it('waits on hydration rather than assuming nothing is saved', async () => {
    useViewportStore.setState({ saved: null, hydrated: false });

    render(<MapScreen />);

    expect(screen.getByText('Finding your location…')).toBeOnTheScreen();
    // Crucially: it has NOT gone to the GPS, because "not hydrated yet" is not
    // the same as "first launch".
    expect(perms).not.toHaveBeenCalled();

    // Hydration lands with a saved viewport → promote straight to the map.
    await act(async () => {
      useViewportStore.setState({ saved: SAVED, hydrated: true });
    });

    expect(screen.getByTestId('MapView')).toBeOnTheScreen();
    expect(perms).not.toHaveBeenCalled();
  });

  it('centres on the user on a genuine first launch', async () => {
    perms.mockResolvedValue(undetermined);
    render(<MapScreen />);

    // Interim state while the prompt + fix resolve — not a flash of Montevideo.
    expect(screen.getByText('Finding your location…')).toBeOnTheScreen();

    await waitFor(() => expect(screen.getByTestId('MapView')).toBeOnTheScreen());

    expect(requestPerms).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('MapView').props.initialRegion).toEqual({
      ...FIX,
      latitudeDelta: 0.02,
      longitudeDelta: 0.02,
    });
  });

  it('falls back to the default region when location is refused, and says why once', async () => {
    perms.mockResolvedValue(deniedBlocked);
    render(<MapScreen />);

    await waitFor(() => expect(screen.getByTestId('MapView')).toBeOnTheScreen());

    expect(screen.getByTestId('MapView').props.initialRegion).toEqual(DEFAULT_REGION);
    // Blocked permanently → the hint is shown unprompted, with the Settings jump.
    expect(screen.getByText('Location is off for Reelmap')).toBeOnTheScreen();
    expect(screen.getByText('Open Settings')).toBeOnTheScreen();
  });

  it('honours a deep-linked lat/lng over everything else', async () => {
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    mockRouter.params = { lat: '10.5', lng: '20.5' };

    render(<MapScreen />);

    expect(screen.getByTestId('MapView').props.initialRegion).toEqual({
      latitude: 10.5,
      longitude: 20.5,
      latitudeDelta: 0.02,
      longitudeDelta: 0.02,
    });
    expect(perms).not.toHaveBeenCalled();
  });

  // T-156: the opening viewport is resolved synchronously (above), and a fix
  // that lands afterwards can still improve the frame. Both branches, because
  // the failure modes are opposite: never re-framing strands a user who moved
  // across town on last night's viewport, and always re-framing yanks a
  // traveller onto an empty map hundreds of kilometres from every pin.
  describe('re-framing on the viewer', () => {
    /** A point `metres` due north of the saved viewport's centre. */
    const northOfSaved = (metres: number) => ({
      latitude: SAVED.latitude + metres / 111_320,
      longitude: SAVED.longitude,
    });

    it('moves onto a viewer who is near their own places', async () => {
      useViewportStore.setState({ saved: SAVED, hydrated: true });
      mockViewer.current = northOfSaved(3_000);

      render(<MapScreen />);
      await waitFor(() => expect(animateToRegion).toHaveBeenCalled());

      // The saved viewport still framed the FIRST paint — the move is an
      // improvement on it, not a replacement for resolving it.
      expect(screen.getByTestId('MapView').props.initialRegion).toEqual(SAVED);
      expect(animateToRegion).toHaveBeenCalledWith(
        { ...mockViewer.current, latitudeDelta: 0.02, longitudeDelta: 0.02 },
        450,
      );
    });

    it('leaves a distant viewer on the viewport they left', async () => {
      useViewportStore.setState({ saved: SAVED, hydrated: true });
      // London saved, viewer in Madrid — every pin they own is ~1,200 km away,
      // so centring on them would show an empty map.
      mockViewer.current = FIX;

      render(<MapScreen />);
      // Nothing to wait FOR, so drain the effects and assert the absence.
      await act(async () => {});

      expect(screen.getByTestId('MapView').props.initialRegion).toEqual(SAVED);
      expect(animateToRegion).not.toHaveBeenCalled();
    });

    it('does not re-frame a deep link, even from right next door', async () => {
      // `?lat=&lng=` is an explicit "show me THIS". A viewer standing beside it
      // is exactly the case where a well-meaning re-frame would quietly discard
      // the thing the user asked to see.
      useViewportStore.setState({ saved: SAVED, hydrated: true });
      mockRouter.params = { lat: '51.5', lng: '-0.12' };
      mockViewer.current = northOfSaved(500);

      render(<MapScreen />);
      await act(async () => {});

      expect(animateToRegion).not.toHaveBeenCalled();
    });
  });
});

describe('locate control', () => {
  beforeEach(() => {
    useViewportStore.setState({ saved: SAVED, hydrated: true });
  });

  it('flies to the user when tapped', async () => {
    render(<MapScreen />);

    await act(async () => {
      fireEvent.press(screen.getByLabelText('Center on my location'));
    });

    expect(animateToRegion).toHaveBeenCalledWith(
      { ...FIX, latitudeDelta: 0.02, longitudeDelta: 0.02 },
      350,
    );
  });

  it('ignores a double-tap rather than firing two GPS requests', async () => {
    // Hold the fix open so the button is still in flight for the second tap.
    let release: (v: unknown) => void = () => {};
    lastKnown.mockReturnValue(new Promise((r) => (release = r)) as never);
    render(<MapScreen />);

    const button = screen.getByLabelText('Center on my location');
    fireEvent.press(button);
    fireEvent.press(button);

    await act(async () => {
      release({ coords: FIX });
    });

    expect(lastKnown).toHaveBeenCalledTimes(1);
  });

  it('shows the Settings hint when permission is permanently blocked', async () => {
    perms.mockResolvedValue(deniedBlocked);
    requestPerms.mockResolvedValue(deniedBlocked);
    render(<MapScreen />);

    expect(screen.queryByText('Location is off for Reelmap')).toBeNull();

    await act(async () => {
      fireEvent.press(screen.getByLabelText('Center on my location'));
    });

    expect(screen.getByText('Location is off for Reelmap')).toBeOnTheScreen();
    expect(animateToRegion).not.toHaveBeenCalled();

    // And the hint deep-links to the OS settings page.
    const openSettings = jest.spyOn(Linking, 'openSettings').mockResolvedValue(undefined);
    await act(async () => {
      fireEvent.press(screen.getByText('Open Settings'));
    });
    expect(openSettings).toHaveBeenCalled();
    openSettings.mockRestore();
  });

  it('is dismissible — the map stays usable without location', async () => {
    perms.mockResolvedValue(deniedBlocked);
    requestPerms.mockResolvedValue(deniedBlocked);
    render(<MapScreen />);

    await act(async () => {
      fireEvent.press(screen.getByLabelText('Center on my location'));
    });
    expect(screen.getByText('Location is off for Reelmap')).toBeOnTheScreen();

    fireEvent.press(screen.getByLabelText('Close'));

    expect(screen.queryByText('Location is off for Reelmap')).toBeNull();
    expect(screen.getByTestId('MapView')).toBeOnTheScreen();
  });

  it('explains a missing fix instead of failing silently', async () => {
    // A watch that never calls back — indoors, a tunnel, a sim with no location.
    // Since the fresh-fix path became cancellable this no longer resolves
    // instantly: it runs out its 5s budget, THEN reports. Advancing the clock is
    // the point, not a workaround — it pins that the budget is actually bounded.
    jest.useFakeTimers();
    lastKnown.mockResolvedValue(null);
    watchEmits(null);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    render(<MapScreen />);

    await act(async () => {
      fireEvent.press(screen.getByLabelText('Center on my location'));
    });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(5_000);
    });

    expect(alert).toHaveBeenCalledWith('Couldn’t get your location. Try again in a moment.');
    expect(animateToRegion).not.toHaveBeenCalled();
    alert.mockRestore();
    jest.useRealTimers();
  });
});

describe('reset view', () => {
  it('returns to the user position once we have one, not the seed city', async () => {
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    render(<MapScreen />);

    // Establish a user position via the locate control...
    await act(async () => {
      fireEvent.press(screen.getByLabelText('Center on my location'));
    });
    animateToRegion.mockClear();

    // ...then reset should come back to it.
    fireEvent.press(screen.getByLabelText('Reset map view'));

    expect(animateToRegion).toHaveBeenCalledWith(
      { ...FIX, latitudeDelta: 0.02, longitudeDelta: 0.02 },
      350,
    );
  });

  it('falls back to the default region when no user position is known', async () => {
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    render(<MapScreen />);

    fireEvent.press(screen.getByLabelText('Reset map view'));

    expect(animateToRegion).toHaveBeenCalledWith(DEFAULT_REGION, 350);
  });
});

describe('viewport persistence', () => {
  const settled = { latitude: 1, longitude: 2, latitudeDelta: 0.03, longitudeDelta: 0.03 };

  it('remembers a viewport the user panned to', async () => {
    jest.useFakeTimers();
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    render(<MapScreen />);

    // A real pan: onPanDrag marks the interaction, then the settle persists it.
    fireEvent(screen.getByTestId('MapView'), 'panDrag');
    fireEvent(screen.getByTestId('MapView'), 'regionChangeComplete', settled);

    // Shares the fetch debounce — nothing is written mid-gesture.
    expect(useViewportStore.getState().saved).toEqual(SAVED);

    act(() => {
      jest.advanceTimersByTime(400);
    });

    expect(useViewportStore.getState().saved).toEqual(settled);
    jest.useRealTimers();
  });

  it('does NOT remember a non-gesture settle (the fallback-poisoning bug)', async () => {
    // Regression: onRegionChangeComplete also fires on the map's initial layout.
    // Persisting that saved whatever we opened at — including a DEFAULT_REGION
    // fallback nobody chose — after which the saved rung beat the location rung
    // forever and the map opened on the fallback city even once location was
    // granted. Observed on device: granting permission changed nothing.
    jest.useFakeTimers();
    useViewportStore.setState({ saved: null, hydrated: true });
    perms.mockResolvedValue(deniedBlocked); // force the DEFAULT_REGION fallback

    render(<MapScreen />);
    await act(async () => {});

    fireEvent(screen.getByTestId('MapView'), 'regionChangeComplete', DEFAULT_REGION);
    act(() => {
      jest.advanceTimersByTime(400);
    });

    expect(useViewportStore.getState().saved).toBeNull();
    jest.useRealTimers();
  });

  it('remembers a viewport reached with the zoom control', async () => {
    // Zooming is a user action, so its settle counts — moveMap marks it.
    jest.useFakeTimers();
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    render(<MapScreen />);

    fireEvent.press(screen.getByLabelText('Zoom in'));
    fireEvent(screen.getByTestId('MapView'), 'regionChangeComplete', settled);
    act(() => {
      jest.advanceTimersByTime(400);
    });

    expect(useViewportStore.getState().saved).toEqual(settled);
    jest.useRealTimers();
  });

  it('does not rely on the Android-only isGesture flag', async () => {
    // Regression: gating persistence on details.isGesture typechecked (the field
    // is optional) but iOS never sends it — AIRMapManager.m builds a payload of
    // `region` alone — so persistence silently never happened on iOS. Verified
    // broken on device. A settle after a real pan must persist even with NO
    // details argument at all.
    jest.useFakeTimers();
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    render(<MapScreen />);

    fireEvent(screen.getByTestId('MapView'), 'panDrag');
    fireEvent(screen.getByTestId('MapView'), 'regionChangeComplete', settled); // no details
    act(() => {
      jest.advanceTimersByTime(400);
    });

    expect(useViewportStore.getState().saved).toEqual(settled);
    jest.useRealTimers();
  });

  it('still refetches for an un-remembered settle', async () => {
    // The fetch and the persistence are deliberately decoupled: the mount settle
    // must still load pins for wherever the map opened.
    jest.useFakeTimers();
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    mapData.current = { pins: [], clusters: [], truncated: true };
    render(<MapScreen />);

    fireEvent(screen.getByTestId('MapView'), 'regionChangeComplete', settled);
    act(() => {
      jest.advanceTimersByTime(400);
    });

    // truncated:true renders the "zoom in" chip, proving the query region moved.
    expect(screen.getByText('Zoom in for more places')).toBeOnTheScreen();
    jest.useRealTimers();
  });

  it('drops a pending settle when the screen unmounts mid-pan', () => {
    // Navigating away inside the 400 ms window used to leave the timer armed:
    // it then fired on an unmounted tree and persisted a viewport for a map the
    // user had already left.
    jest.useFakeTimers();
    useViewportStore.setState({ saved: SAVED, hydrated: true });
    const { unmount } = render(<MapScreen />);

    fireEvent(screen.getByTestId('MapView'), 'panDrag');
    fireEvent(screen.getByTestId('MapView'), 'regionChangeComplete', settled);
    unmount();

    act(() => {
      jest.advanceTimersByTime(400);
    });

    expect(useViewportStore.getState().saved).toEqual(SAVED);
    jest.useRealTimers();
  });
});
