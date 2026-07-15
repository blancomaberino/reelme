/* eslint-disable @typescript-eslint/no-require-imports */
import { act, fireEvent, render, screen } from '@testing-library/react-native';

import type { MapData } from '@/api/hooks/useMapPlaces';
import type { MapPin } from '@/api/places';
import { useMapStore } from '@/stores/map';

import MapScreen from '../map';

import { mockRouter } from '../../../jest.setup';

// --- Mocks: feed the screen fixture data, and count PlaceMarker renders. ---
const mapData: { current: MapData } = { current: { pins: [], clusters: [], truncated: false } };
// Return fresh array references each render, as react-query does with
// keepPreviousData — so tests exercise the marker-memoization invariant under
// real reference churn (the handlers must stay stable across "refetches").
jest.mock('@/api/hooks/useMapPlaces', () => ({
  useMapPlaces: () => ({
    data: {
      pins: [...mapData.current.pins],
      clusters: [...mapData.current.clusters],
      truncated: mapData.current.truncated,
    },
    isFetching: false,
    isSuccess: true,
  }),
}));
jest.mock('@/api/hooks/useTags', () => ({ usePopularTags: () => ({ data: [] }) }));
// The quick-share popup drives its own react-query hooks (create + poll) that
// need a QueryClientProvider the map test doesn't set up — it has its own test.
// Here, a light stub that just reflects `visible`, so the "+" button is testable.
jest.mock('@/components/map/quick-share', () => {
  const React = require('react');
  const { Text } = require('react-native');
  return {
    QuickShareModal: ({ visible }: { visible: boolean }) =>
      visible ? React.createElement(Text, null, 'quick-share-open') : null,
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
  mapData.current = { pins: [pin('1'), pin('2'), pin('3')], clusters: [], truncated: false };
  useMapStore.setState({ selected: null, filters: { cuisine: null, price_range: null, tags: [] } });
  mockRouter.push.mockClear();
  mockRouter.params = {};
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

it('opens the quick-add popup from the header "+" button', () => {
  render(<MapScreen />);
  expect(screen.queryByText('quick-share-open')).toBeNull();
  fireEvent.press(screen.getByLabelText('Add from a link'));
  expect(screen.getByText('quick-share-open')).toBeOnTheScreen();
});
