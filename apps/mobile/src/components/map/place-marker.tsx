import { memo, useEffect, useRef, useState } from 'react';
import { Marker } from 'react-native-maps';

import type { MapPin } from '@/api/places';

import { DOT_ANCHOR, MARKER_ANCHOR, PinGlyph } from './pin-glyph';

/**
 * What a marker needs to draw itself — the fields common to a viewport `MapPin`
 * and a list `PlaceSummary`.
 *
 * Typed as the intersection rather than `MapPin`, because that requirement was
 * the whole reason five other map screens hand-rolled a bare `<Marker>`: they
 * are fed by LIST endpoints, could not satisfy `MapPin`, and each quietly grew
 * its own default red pin instead. A structural minimum is what lets every map
 * in the app draw the same thing.
 */
export type MarkerPlace = Pick<MapPin, 'id' | 'name' | 'lat' | 'lng' | 'category' | 'price_range'> & {
  // OPTIONAL, not just nullable: the contract marks it optional on the list
  // shape and required-nullable on the viewport one. Taking `Pick<MapPin>`
  // wholesale rejected every PlaceSummary — which is the sort of small
  // mismatch that sends someone back to writing a bare <Marker>.
  thumbnail_url?: string | null;
};

// No `PlaceSummary extends MarkerPlace ? …` alias here: a conditional type that
// resolves to `never` is not a compile ERROR, so it would look like a guarantee
// while asserting nothing. The real check is the call sites, which pass
// PlaceSummary directly and fail loudly if the shape drifts.

type Props = {
  pin: MarkerPlace;
  selected: boolean;
  /** Zoomed-in enough to show the full photo/teardrop + name; otherwise a dot. */
  detailed: boolean;
  /** Stable module/parent-level callback; reads the id from the event. */
  onPress: (id: string) => void;
  /**
   * Draw the name under the pin. On by default — it is what makes a pin legible
   * among others. Off for a single-place preview whose title is already on the
   * screen an inch above it, where the label is pure duplication.
   */
  showName?: boolean;
};

/**
 * A single place marker (T-032 §4, the load-bearing perf pattern). Memoized on
 * `(id, selected, detailed)` — pin data is immutable per fetch — with a stable
 * `onPress` and `tracksViewChanges` off once settled, so Android doesn't
 * re-rasterize every marker every frame. Never pass inline closures/objects here.
 */
function PlaceMarkerBase({ pin, selected, detailed, onPress, showName = true }: Props) {
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
        name={detailed && showName ? pin.name : undefined}
        compact={!detailed}
        category={pin.category}
        priceRange={pin.price_range}
        selected={selected}
        onThumbSettled={() => setContentReady(true)}
      />
    </Marker>
  );
}

/**
 * Every field of {@link MarkerPlace}, as a `Record` keyed by the type — so
 * adding a field there without adding it here is a COMPILE error.
 *
 * The comparator used to list fields by hand. First it compared only `pin.id`,
 * which was safe while pins came from the viewport endpoint (a row is fixed per
 * fetch) and wrong the moment the mini-map started polling `usePlace` so that
 * enrichment could fill imagery in late. Then it grew `thumbnail_url`/`name`/
 * `price_range` — and still missed `lat`/`lng`, which are the marker's actual
 * COORDINATE, so a geocode correction left the pin at the old position. A
 * hand-maintained list of "everything that matters" drifts every time the type
 * moves; this one cannot.
 */
const MARKER_FIELDS: Record<keyof MarkerPlace, true> = {
  id: true,
  name: true,
  lat: true,
  lng: true,
  category: true,
  price_range: true,
  thumbnail_url: true,
};

const MARKER_KEYS = Object.keys(MARKER_FIELDS) as (keyof MarkerPlace)[];

/** Shallow equality over every field the marker draws. All scalars — cheap. */
function samePin(a: MarkerPlace, b: MarkerPlace): boolean {
  return MARKER_KEYS.every((key) => a[key] === b[key]);
}

export const PlaceMarker = memo(
  PlaceMarkerBase,
  (prev, next) =>
    samePin(prev.pin, next.pin) &&
    prev.selected === next.selected &&
    prev.detailed === next.detailed &&
    prev.showName === next.showName &&
    prev.onPress === next.onPress,
);
