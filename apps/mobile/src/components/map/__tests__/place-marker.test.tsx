/* eslint-disable @typescript-eslint/no-require-imports */
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { Image } from 'react-native';

import type { MapPin } from '@/api/places';

import { PlaceMarker } from '../place-marker';

// Mock react-native-maps' Marker to a plain View that forwards the props we
// assert on (tracksViewChanges, accessibilityLabel) and renders its children,
// so we can exercise the real PinGlyph underneath.
jest.mock('react-native-maps', () => {
  const React = require('react');
  const { View } = require('react-native');
  return {
    Marker: ({ children, tracksViewChanges, accessibilityLabel, anchor, onPress }: Record<string, unknown>) =>
      React.createElement(
        View,
        { testID: 'marker', tracksViewChanges, accessibilityLabel, anchor, onPress },
        children as React.ReactNode,
      ),
  };
});

function pin(over: Partial<MapPin> = {}): MapPin {
  return {
    type: 'place',
    id: '1',
    name: 'Café Brasilero',
    lat: -34.9,
    lng: -56.16,
    category: null,
    city: 'Montevideo',
    price_range: 2,
    status: 'active',
    tags: [],
    source_count: 1,
    has_active_offer: false,
    thumbnail_url: null,
    top_influencer: null,
    ...over,
  };
}

const noop = () => {};
const tracks = () => screen.getByTestId('marker').props.tracksViewChanges;
const anchor = () => screen.getByTestId('marker').props.anchor;

it('anchors the coordinate to the pointer tip when detailed and the dot centre when compact', () => {
  // Detailed: a fixed, off-centre hotspot (the pointer tip) so the glyph can
  // grow/shrink without dragging the marker off its real position.
  const { rerender } = render(<PlaceMarker pin={pin()} selected={false} detailed onPress={noop} />);
  expect(anchor()).toEqual({ x: 0.5, y: expect.any(Number) });
  expect(anchor().y).toBeGreaterThan(0.5);

  // Compact: a symmetric dot centred on the coordinate.
  rerender(<PlaceMarker pin={pin()} selected={false} detailed={false} onPress={noop} />);
  expect(anchor()).toEqual({ x: 0.5, y: 0.5 });
});

it('shows the name label when zoomed in (detailed)', () => {
  render(<PlaceMarker pin={pin()} selected={false} detailed onPress={noop} />);
  expect(screen.getByText('Café Brasilero')).toBeTruthy();
});

it('collapses to a bare dot when zoomed out — no photo, no name', () => {
  render(<PlaceMarker pin={pin({ thumbnail_url: 'https://cdn.example/reel.jpg' })} selected={false} detailed={false} onPress={noop} />);
  expect(screen.UNSAFE_queryAllByType(Image)).toHaveLength(0);
  expect(screen.queryByText('Café Brasilero')).toBeNull();
  // A dot is synchronous content — the marker freezes immediately.
  expect(tracks()).toBe(false);
});

it('falls back to the price teardrop when detailed but the place has no poster', () => {
  render(<PlaceMarker pin={pin({ price_range: 2 })} selected={false} detailed onPress={noop} />);
  expect(screen.UNSAFE_queryAllByType(Image)).toHaveLength(0);
  expect(screen.getByText('$$')).toBeTruthy();
  expect(tracks()).toBe(false);
});

it('draws a photo bubble and keeps tracking view changes until the poster loads', () => {
  render(<PlaceMarker pin={pin({ thumbnail_url: 'https://cdn.example/reel.jpg' })} selected={false} detailed onPress={noop} />);

  const image = screen.UNSAFE_getByType(Image);
  expect(image.props.source).toEqual({ uri: 'https://cdn.example/reel.jpg' });
  // Before the bitmap settles the marker must keep re-rasterizing, else it
  // freezes on a blank frame (the react-native-maps image-marker gotcha).
  expect(tracks()).toBe(true);

  fireEvent(image, 'load');
  expect(tracks()).toBe(false);
});

it('still freezes if the poster errors (no forever-tracking marker)', () => {
  render(<PlaceMarker pin={pin({ thumbnail_url: 'https://cdn.example/gone.jpg' })} selected={false} detailed onPress={noop} />);
  fireEvent(screen.UNSAFE_getByType(Image), 'error');
  expect(tracks()).toBe(false);
});

it('re-arms tracking when the zoom detail level changes (dot → photo)', () => {
  const p = pin({ thumbnail_url: 'https://cdn.example/reel.jpg' });
  const { rerender } = render(<PlaceMarker pin={p} selected={false} detailed={false} onPress={noop} />);
  expect(tracks()).toBe(false); // dot, settled

  // Zoom in: the marker becomes a photo bubble and must recapture (track again).
  rerender(<PlaceMarker pin={p} selected detailed onPress={noop} />);
  expect(screen.UNSAFE_getByType(Image)).toBeTruthy();
  expect(tracks()).toBe(true);
});

it('re-arms then re-settles when collapsing back to a dot (photo → dot)', async () => {
  const p = pin({ thumbnail_url: 'https://cdn.example/reel.jpg' });
  const { rerender } = render(<PlaceMarker pin={p} selected={false} detailed onPress={noop} />);
  fireEvent(screen.UNSAFE_getByType(Image), 'load');
  expect(tracks()).toBe(false);

  // Zoom out: back to a dot. Tracking re-arms for the recapture, then settles.
  rerender(<PlaceMarker pin={p} selected={false} detailed={false} onPress={noop} />);
  await waitFor(() => expect(tracks()).toBe(false));
  expect(screen.UNSAFE_queryAllByType(Image)).toHaveLength(0);
});

it('keeps tracking view changes while selected so the highlight re-rasterizes', () => {
  render(<PlaceMarker pin={pin()} selected detailed onPress={noop} />);
  expect(tracks()).toBe(true);
});

it('reports the tapped pin id', () => {
  const onPress = jest.fn();
  render(<PlaceMarker pin={pin({ id: '42' })} selected={false} detailed onPress={onPress} />);
  fireEvent.press(screen.getByTestId('marker'));
  expect(onPress).toHaveBeenCalledWith('42');
});
