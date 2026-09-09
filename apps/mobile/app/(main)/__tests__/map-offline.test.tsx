import { fireEvent, render, screen } from '@testing-library/react-native';

import type { MapData } from '@/api/hooks/useMapPlaces';
import type { MapPin } from '@/api/places';
import { DEFAULT_REGION } from '@/lib/initial-region';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';
import { useViewportStore } from '@/stores/viewport';

import MapScreen from '../map';

/**
 * An empty map has three causes and looks identical in all three (T-103). The
 * app-wide ConnectionBanner owns "offline"; the map itself has to own "the
 * request failed", because otherwise a 500 is indistinguishable from "you have
 * no saved places" — and one of those is the user's fault to fix.
 */

// The viewer point (T-156) lives in the query cache, and this file renders the
// screen without a QueryClientProvider — the same reason every other hook here
// is mocked. Its own contract (never prompts; null unless already granted) is
// pinned in src/lib/__tests__/use-viewer-position.test.ts.
jest.mock('@/lib/use-viewer-position', () => ({
  useViewerPosition: () => null,
  useRefreshViewerPosition: () => () => {},
}));
const mockQuery: { current: { data: MapData | undefined; isError: boolean } } = {
  current: { data: { pins: [], clusters: [], truncated: false, fetchedAt: Date.now() }, isError: false },
};
const mockRefetch = jest.fn();
jest.mock('@/api/hooks/useMapPlaces', () => ({
  useMapPlaces: () => ({
    data: mockQuery.current.data,
    isError: mockQuery.current.isError,
    isFetching: false,
    refetch: mockRefetch,
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

function pin(id: string): MapPin {
  return {
    type: 'place',
    id,
    name: `Place ${id}`,
    lat: -34.9,
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
  };
}

beforeEach(() => {
  mockRefetch.mockClear();
  mockQuery.current = { data: { pins: [], clusters: [], truncated: false, fetchedAt: Date.now() }, isError: false };
  useMapStore.setState({
    selected: null,
    filters: { cuisine: null, price_range: null, tags: [], list: null, filter: null },
  });
  useSessionStore.setState({ user: null, status: 'authed' });
  useViewportStore.setState({ saved: DEFAULT_REGION, hydrated: true });
});

it('shows nothing extra when the map is simply empty', () => {
  render(<MapScreen />);

  expect(screen.queryByTestId('map-error-chip')).toBeNull();
});

it('says the fetch failed, with a retry, when the map has no pins to show', () => {
  mockQuery.current = { data: { pins: [], clusters: [], truncated: false, fetchedAt: Date.now() }, isError: true };

  render(<MapScreen />);

  expect(screen.getByText('Couldn’t load places')).toBeTruthy();
  fireEvent.press(screen.getByTestId('map-error-chip'));
  expect(mockRefetch).toHaveBeenCalled();
});

it('stays quiet when a failed refetch still has cached pins on screen', () => {
  // The offline/stale case: showing the pins beats shouting about the request.
  mockQuery.current = { data: { pins: [pin('1')], clusters: [], truncated: false, fetchedAt: Date.now() }, isError: true };

  render(<MapScreen />);

  expect(screen.queryByTestId('map-error-chip')).toBeNull();
});
