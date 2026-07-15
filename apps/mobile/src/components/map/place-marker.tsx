import { memo, useEffect, useRef, useState } from 'react';
import { Marker } from 'react-native-maps';

import type { MapPin } from '@/api/places';

import { DOT_ANCHOR, MARKER_ANCHOR, PinGlyph } from './pin-glyph';

type Props = {
  pin: MapPin;
  selected: boolean;
  /** Zoomed-in enough to show the full photo/teardrop + name; otherwise a dot. */
  detailed: boolean;
  /** Stable module/parent-level callback; reads the id from the event. */
  onPress: (id: string) => void;
};

/**
 * A single place marker (T-032 §4, the load-bearing perf pattern). Memoized on
 * `(id, selected, detailed)` — pin data is immutable per fetch — with a stable
 * `onPress` and `tracksViewChanges` off once settled, so Android doesn't
 * re-rasterize every marker every frame. Never pass inline closures/objects here.
 */
function PlaceMarkerBase({ pin, selected, detailed, onPress }: Props) {
  const showPhoto = detailed && pin.thumbnail_url != null;

  // react-native-maps rasterizes the marker's children into a bitmap and, with
  // tracksViewChanges off, never redraws it. So we must keep tracking through
  // any content change — the zoom detail level (dot ⇄ photo/teardrop) as well as
  // the first image load — until the new content settles, then flip to false so
  // that final true→false transition captures it once. A synchronous glyph
  // settles on the next frame; a photo bubble settles on its <Image> onLoad
  // (else the marker freezes on a blank frame — the classic image-marker gotcha).
  const [contentReady, setContentReady] = useState(!showPhoto);
  const mounted = useRef(false);
  useEffect(() => {
    // Skip the first run: react-native-maps captures once on mount regardless,
    // so a freshly-mounted synchronous glyph needn't re-arm tracking.
    if (!mounted.current) {
      mounted.current = true;
      return;
    }
    setContentReady(false);
    if (!showPhoto) {
      const id = requestAnimationFrame(() => setContentReady(true));
      return () => cancelAnimationFrame(id);
    }
    // Photo: settled by the image's onLoad/onError below.
  }, [detailed, showPhoto]);

  return (
    <Marker
      // Remount when the detail level flips: react-native-maps doesn't reliably
      // re-apply a *changed* anchor to a live marker whose view also resizes
      // (the dot drifted off-point on zoom-out). A fresh marker per state mounts
      // with its own constant anchor, so the hotspot stays glued to the point.
      key={detailed ? 'detailed' : 'compact'}
      identifier={pin.id}
      coordinate={{ latitude: pin.lat, longitude: pin.lng }}
      // Pin the coordinate to a fixed hotspot per state (dot centre / pointer
      // tip) so the marker sits exactly on its location.
      anchor={detailed ? MARKER_ANCHOR : DOT_ANCHOR}
      tracksViewChanges={selected || !contentReady}
      onPress={() => onPress(pin.id)}
      accessibilityLabel={pin.name}
    >
      <PinGlyph
        thumbnailUrl={showPhoto ? pin.thumbnail_url : null}
        name={detailed ? pin.name : undefined}
        compact={!detailed}
        category={pin.category}
        priceRange={pin.price_range}
        selected={selected}
        onThumbSettled={() => setContentReady(true)}
      />
    </Marker>
  );
}

export const PlaceMarker = memo(
  PlaceMarkerBase,
  (prev, next) =>
    prev.pin.id === next.pin.id &&
    prev.selected === next.selected &&
    prev.detailed === next.detailed &&
    prev.onPress === next.onPress,
);
