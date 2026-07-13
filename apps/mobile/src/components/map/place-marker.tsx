import { memo } from 'react';
import { Marker } from 'react-native-maps';

import type { MapPin } from '@/api/places';

import { PinGlyph } from './pin-glyph';

type Props = {
  pin: MapPin;
  selected: boolean;
  /** Stable module/parent-level callback; reads the id from the event. */
  onPress: (id: string) => void;
};

/**
 * A single place marker (T-032 §4, the load-bearing perf pattern). Memoized on
 * `(id, selected)` only — pin data is immutable per fetch — with a stable
 * `onPress` and `tracksViewChanges={false}` so Android doesn't re-rasterize
 * every marker every frame. Never pass inline closures/objects here.
 */
function PlaceMarkerBase({ pin, selected, onPress }: Props) {
  return (
    <Marker
      identifier={pin.id}
      coordinate={{ latitude: pin.lat, longitude: pin.lng }}
      tracksViewChanges={false}
      onPress={() => onPress(pin.id)}
      accessibilityLabel={pin.name}
    >
      <PinGlyph category={pin.category} priceRange={pin.price_range} selected={selected} />
    </Marker>
  );
}

export const PlaceMarker = memo(
  PlaceMarkerBase,
  (prev, next) => prev.pin.id === next.pin.id && prev.selected === next.selected && prev.onPress === next.onPress,
);
