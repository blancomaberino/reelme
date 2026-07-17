/* eslint-disable @typescript-eslint/no-require-imports */
import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Alert } from 'react-native';

import type { MapData } from '@/api/hooks/useMapPlaces';
import type { MapFilters } from '@/api/keys';
import type { MapPin } from '@/api/places';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';

import MapScreen from '../map';

import { mockRouter } from '../../../jest.setup';

// The react-native-maps mock (jest.setup) exposes a persistent animateToRegion
// spy so imperative map moves (reset view, fly-to) are assertable.
const { __animateToRegion: animateToRegion } = jest.requireMock('react-native-maps') as {
  __animateToRegion: jest.Mock;
};

// --- Mocks: feed the screen fixture data, and count PlaceMarker renders. ---
const mapData: { current: MapData } = { current: { pins: [], clusters: [], truncated: false } };
// Captures the (derived) filters the screen hands the fetch — for the T-071
// personal-scope derivation tests. `mock`-prefixed so jest allows it in the
// hoisted factory.
const mockFiltersSeen: { current: MapFilters | undefined } = { current: undefined };
// Return fresh array references each render, as react-query does with
// keepPreviousData — so tests exercise the marker-memoization invariant under
// real reference churn (the handlers must stay stable across "refetches").
jest.mock('@/api/hooks/useMapPlaces', () => ({
  useMapPlaces: (_region: unknown, filters: MapFilters) => {
    mockFiltersSeen.current = filters;
    return {
      data: {
        pins: [...mapData.current.pins],
        clusters: [...mapData.current.clusters],
        truncated: mapData.current.truncated,
      },
      isFetching: false,
      isSuccess: true,
    };
  },
}));
jest.mock('@/api/hooks/useTags', () => ({
  usePopularTags: () => ({ data: [] }),
  useTagCatalog: () => ({ data: [] }),
  useMyPlacesTags: () => ({ data: [] }),
}));
jest.mock('@/api/hooks/usePaymentCards', () => ({ usePaymentCards: () => ({ data: [] }) }));
// The map screen removes a list-scoped pin via useListMembership().remove; the
// map test has no QueryClientProvider, so stub the hook and capture the mutate.
const mockRemoveMutate = jest.fn();
jest.mock('@/api/hooks/useLists', () => ({
  useListMembership: () => ({ remove: { mutate: mockRemoveMutate }, add: { mutate: jest.fn() } }),
}));
// The quick-share popup drives its own react-query hooks (create + poll) that
// need a QueryClientProvider the map test doesn't set up — it has its own test.
// Here, a light stub that just reflects `visible`, so the "+" button is testable.
jest.mock('@/components/map/quick-share', () => {
  const React = require('react');
  const { Pressable, Text } = require('react-native');
  // Stub reflects `visible` and exposes a button that fires onPublished with a
  // fixture place, so the screen's post-publish wiring (fly + open) is testable.
  return {
    QuickShareModal: ({ visible, onPublished }: { visible: boolean; onPublished: (p: unknown) => void }) =>
      visible
        ? React.createElement(
            Pressable,
            {
              accessibilityLabel: 'stub-publish',
              onPress: () => onPublished({ id: 'p9', name: 'New Place', lat: -34.9, lng: -56.1 }),
            },
            React.createElement(Text, null, 'quick-share-open'),
          )
        : null,
  };
});

const markerRenders: string[] = [];
// Mock PlaceMarker with the SAME memo comparator as the real component, so the
// render counter reflects whether the SCREEN passes stable props (the thing
// under test): unrelated markers must not re-render when one pin is selected.
jest.mock('@/components/map/place-marker', () => {
  const React = require('react');
  const { Pressable, Text } = require('react-native');
  const Base = ({ pin, onPress }: { pin: { id: string; name: string }; onPress: (id: string) => void }) => {
    markerRenders.push(pin.id);
    return React.createElement(
      Pressable,
      { accessibilityLabel: `marker-${pin.id}`, onPress: () => onPress(pin.id) },
      React.createElement(Text, null, pin.name),
    );
  };
  return {
    PlaceMarker: React.memo(
      Base,
      (
        prev: { pin: { id: string }; selected: boolean; onPress: unknown },
        next: { pin: { id: string }; selected: boolean; onPress: unknown },
      ) => prev.pin.id === next.pin.id && prev.selected === next.selected && prev.onPress === next.onPress,
    ),
  };
});

function pin(id: string, over: Partial<MapPin> = {}): MapPin {
  return {
    type: 'place',
    id,
    name: `Place ${id}`,
    lat: -34.9 + Number(id) * 0.001,
    lng: -56.16,
    category: null,
    city: 'Montevideo',
    price_range: 2,
    status: 'pending',
    tags: [],
    source_count: 1,
    has_active_offer: false,
    thumbnail_url: null,
    top_influencer: null,
    ...over,
  };
}

beforeEach(() => {
  markerRenders.length = 0;
  mockFiltersSeen.current = undefined;
  mockRemoveMutate.mockClear();
  mapData.current = { pins: [pin('1'), pin('2'), pin('3')], clusters: [], truncated: false };
  useMapStore.setState({ selected: null, filters: { cuisine: null, price_range: null, tags: [], list: null, filter: null } });
  useSessionStore.setState({ user: null, status: 'guest' });
  useSettingsStore.setState({ locale: 'en' }); // match jest.setup's default
  mockRouter.push.mockClear();
  mockRouter.params = {};
  animateToRegion.mockClear();
});

// T-071: the home map is the viewer's OWN places — authed → filter=mine, guests
// browse the public map, and an active saved list overrides the personal scope.
it('scopes the map to filter=mine for an authed viewer', () => {
  useSessionStore.setState({ user: null, status: 'authed' });
  render(<MapScreen />);
  expect(mockFiltersSeen.current?.filter).toBe('mine');
});

it('sends no scope for a guest (the public map, no 401)', () => {
  useSessionStore.setState({ user: null, status: 'guest' });
  render(<MapScreen />);
  expect(mockFiltersSeen.current?.filter).toBeNull();
});

it('drops the personal scope while a saved list is the active view', () => {
  useSessionStore.setState({ user: null, status: 'authed' });
  useMapStore.getState().setList({ id: '7', name: 'Trip' });
  render(<MapScreen />);
  expect(mockFiltersSeen.current?.filter).toBeNull();
  expect(mockFiltersSeen.current?.list?.id).toBe('7');
});

it('renders a marker per pin', () => {
  render(<MapScreen />);
  expect(screen.getByLabelText('marker-1')).toBeOnTheScreen();
  expect(screen.getByLabelText('marker-3')).toBeOnTheScreen();
});

it('does not re-render unrelated markers when one pin is selected (memoization)', () => {
  render(<MapScreen />);
  expect(markerRenders.length).toBe(3); // one render per pin

  // Select pin 2 → the screen re-renders, but only pin 2's `selected` flips.
  // With a stable onPress and immutable pin data, memo skips pins 1 and 3 —
  // exactly one additional render (pin 2). If the screen churned props (inline
  // closures / new region state) all three would re-render and this would fail.
  fireEvent.press(screen.getByLabelText('marker-2'));

  expect(useMapStore.getState().selected?.id).toBe('2');
  const rerendered = markerRenders.slice(3);
  expect(rerendered).toEqual(['2']);
});

it('does not re-render markers across a refetch (stable onPress despite new data ref)', () => {
  const view = render(<MapScreen />);
  expect(markerRenders.length).toBe(3);

  // Force a re-render — the mock hands back a NEW pins array (as react-query
  // does each fetch). With handlers held in refs (stable identity) and equal
  // pin ids, the memo must skip every marker: zero additional renders.
  act(() => {
    view.rerender(<MapScreen />);
  });
  expect(markerRenders.length).toBe(3);
});

it('opens the preview sheet with the tapped place and navigates to detail', () => {
  render(<MapScreen />);
  fireEvent.press(screen.getByLabelText('marker-2'));

  // The sheet renders the place name + "View place" CTA.
  expect(screen.getByText('View place')).toBeOnTheScreen();
  fireEvent.press(screen.getByText('View place'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '2' } });
});

it('shows the "zoom in for more" chip when the response is truncated', () => {
  mapData.current = { pins: [pin('1')], clusters: [], truncated: true };
  render(<MapScreen />);
  expect(screen.getByText(/Zoom in for more/)).toBeOnTheScreen();
});

it('routes to search from the header search button', () => {
  render(<MapScreen />);
  fireEvent.press(screen.getByLabelText('Search'));
  expect(mockRouter.push).toHaveBeenCalledWith('/search');
});

// T-078: the reset control snaps the viewport back to the default city view.
it('resets the viewport to the default region on reset button press', () => {
  render(<MapScreen />);
  animateToRegion.mockClear(); // ignore any mount-time moves

  fireEvent.press(screen.getByLabelText('Reset map view'));

  expect(animateToRegion).toHaveBeenCalledTimes(1);
  expect(animateToRegion).toHaveBeenCalledWith(
    { latitude: -34.9, longitude: -56.16, latitudeDelta: 0.15, longitudeDelta: 0.15 },
    expect.any(Number),
  );
});

it('opens the quick-add popup from the header "+" button', () => {
  render(<MapScreen />);
  expect(screen.queryByText('quick-share-open')).toBeNull();
  fireEvent.press(screen.getByLabelText('Add from a link'));
  expect(screen.getByText('quick-share-open')).toBeOnTheScreen();
});

// T-076: publishing from the map quick-add opens the new place's detail.
it('opens the new place detail after a quick-add publish', () => {
  render(<MapScreen />);
  fireEvent.press(screen.getByLabelText('Add from a link'));
  fireEvent.press(screen.getByLabelText('stub-publish'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: 'p9' } });
});

// T-073 follow-up: with a saved list as the active scope, a tapped pin is
// already in that list, so the sheet's action removes it from THAT list.
it('removes a list-scoped pin from that list via the membership mutation', () => {
  useSessionStore.setState({ user: null, status: 'authed' });
  useMapStore.getState().setList({ id: '7', name: 'Trip' });
  const alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

  render(<MapScreen />);
  fireEvent.press(screen.getByLabelText('marker-1'));

  // The sheet offers remove-from-list (not save) while a list is active.
  expect(screen.queryByLabelText('Save to a list')).toBeNull();
  fireEvent.press(screen.getByLabelText('Remove from list'));

  // The confirm dialog names the list (interpolated title) and guards removal.
  expect(alertSpy.mock.calls[0]?.[0]).toContain('Trip');
  const buttons = alertSpy.mock.calls[0]?.[2] as { style?: string; onPress?: () => void }[];

  // Cancelling does NOT mutate…
  act(() => buttons.find((b) => b.style === 'cancel')?.onPress?.());
  expect(mockRemoveMutate).not.toHaveBeenCalled();

  // …only the destructive action removes from the list.
  act(() => buttons.find((b) => b.style === 'destructive')?.onPress?.());
  expect(mockRemoveMutate).toHaveBeenCalledTimes(1);
  expect(mockRemoveMutate).toHaveBeenCalledWith({ listId: '7', placeId: '1' });
  alertSpy.mockRestore();
});

it('offers save (not remove-from-list) on the personal map with no active list', () => {
  useSessionStore.setState({ user: null, status: 'authed' });
  render(<MapScreen />);
  fireEvent.press(screen.getByLabelText('marker-1'));

  expect(screen.getByLabelText('Save to a list')).toBeOnTheScreen();
  expect(screen.queryByLabelText('Remove from list')).toBeNull();
});
