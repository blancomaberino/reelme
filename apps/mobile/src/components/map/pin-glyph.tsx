import { Image, StyleSheet, Text, View } from 'react-native';

import { priceGlyphs } from '@/lib/format';

type Props = {
  /** The place's poster; when present the marker becomes a Google-style photo bubble. */
  thumbnailUrl?: string | null;
  /** Place name, rendered as a label under the marker so a pin is legible at a glance. */
  name?: string;
  /** Zoomed-out state — collapse to a small dot (like Google Maps) instead of a photo/teardrop. */
  compact?: boolean;
  category: string | null;
  priceRange: number | null;
  selected: boolean;
  /** Fires once the thumbnail bitmap has settled (loaded or errored) so the marker can freeze. */
  onThumbSettled?: () => void;
};

// MERCADO pin colors — kept literal (markers render outside the themed tree and
// must read on both light and dark map tiles). Terracotta accent, market-gold
// when selected.
const PIN = '#CF5C34';
const PIN_SELECTED = '#B4842A';

// The detailed marker lives in a fixed-size box with the pointer tip at a fixed
// y (HOTSPOT_Y). Because the box never changes size — the bubble grows upward,
// the name sits below — the map anchor can be a constant fraction, so the tip
// stays glued to the coordinate whatever the pin shows. Without this the marker
// visibly jumps as the content height changes. Exported so PlaceMarker pins the
// <Marker anchor> to the same hotspot.
const MARKER_W = 120;
const MARKER_H = 96;
const HOTSPOT_Y = 64;
export const MARKER_ANCHOR = { x: 0.5, y: HOTSPOT_Y / MARKER_H };
/** The dot is symmetric — anchor its centre on the coordinate. */
export const DOT_ANCHOR = { x: 0.5, y: 0.5 };

/**
 * The map marker visual. When the place has a poster it renders a Google-style
 * photo bubble — a circular thumbnail ringed in the MERCADO accent with a
 * pointer tail — so a pin is identifiable at a glance instead of a blank
 * teardrop. With no image it falls back to the original terracotta teardrop and
 * price tier. Either way the place name is labelled underneath so pins don't get
 * lost against the map. `pointerEvents="none"` is load-bearing (see
 * PlaceMarker): a touchable child would swallow the tap and the parent
 * <Marker onPress> would never fire on iOS.
 *
 * When `compact` (zoomed out) it collapses to a small dot — a lone place reads
 * as a point, not a full photo bubble, matching Google Maps. Density is handled
 * separately by clustering (count bubbles), so dots only stand in for singletons.
 */
export function PinGlyph({ thumbnailUrl, name, compact, priceRange, selected, onThumbSettled }: Props) {
  const color = selected ? PIN_SELECTED : PIN;

  if (compact) {
    const size = selected ? 18 : 13;
    return <View pointerEvents="none" style={[styles.dot, { width: size, height: size, backgroundColor: color }]} />;
  }

  const label =
    name != null && name !== '' ? (
      <View style={styles.nameRow}>
        <Text numberOfLines={1} style={[styles.name, selected && styles.nameSelected]}>
          {name}
        </Text>
      </View>
    ) : null;

  if (thumbnailUrl) {
    const size = selected ? 52 : 44;
    return (
      <View pointerEvents="none" style={styles.container}>
        <View style={styles.glyphCol}>
          <View style={[styles.bubble, { width: size, height: size, borderColor: color }]}>
            <Image
              source={{ uri: thumbnailUrl }}
              style={styles.photo}
              resizeMode="cover"
              // Freeze the marker once the bitmap settles — on error too, so a 404
              // thumbnail doesn't leave the marker re-rasterizing every frame.
              onLoad={onThumbSettled}
              onError={onThumbSettled}
            />
          </View>
          {/* Pointer tail — a small accent triangle anchoring the bubble to the point. */}
          <View style={[styles.tail, { borderTopColor: color }]} />
        </View>
        {label}
      </View>
    );
  }

  const price = priceGlyphs(priceRange) || '•';
  const size = selected ? 40 : 34;
  return (
    <View pointerEvents="none" style={styles.container}>
      <View style={styles.glyphCol}>
        <View style={[styles.teardrop, { width: size, height: size, backgroundColor: color }]}>
          <Text style={styles.priceLabel}>{price}</Text>
        </View>
      </View>
      {label}
    </View>
  );
}

const styles = StyleSheet.create({
  // Fixed-size stage so the anchor hotspot never moves (see MARKER_ANCHOR).
  container: { width: MARKER_W, height: MARKER_H },
  // Glyph (bubble+tail / teardrop) pinned so its bottom tip sits at HOTSPOT_Y;
  // it grows upward, keeping the tip glued to the coordinate.
  glyphCol: { position: 'absolute', left: 0, right: 0, bottom: MARKER_H - HOTSPOT_Y, alignItems: 'center' },
  // Name pill sits just below the hotspot, centered, out of the anchor's way.
  nameRow: { position: 'absolute', left: 0, right: 0, top: HOTSPOT_Y + 6, alignItems: 'center' },

  // Zoomed-out dot — a lone place as a simple point.
  dot: {
    borderRadius: 999,
    borderWidth: 2,
    borderColor: '#FFFFFF',
    shadowColor: '#000',
    shadowOpacity: 0.25,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
    elevation: 3,
  },

  // Name label under the marker — a white pill so the text reads on any tile.
  name: {
    maxWidth: 104,
    color: '#2B2320',
    fontSize: 10.5,
    fontWeight: '700',
    letterSpacing: -0.2,
    textAlign: 'center',
    backgroundColor: 'rgba(255,255,255,0.94)',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 6,
    overflow: 'hidden', // clip the pill's rounded corners around the text bg
    shadowColor: '#000',
    shadowOpacity: 0.18,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
    elevation: 3,
  },
  nameSelected: { color: '#8A5E12', fontWeight: '800' },

  // Photo-bubble marker.
  bubble: {
    borderRadius: 999,
    borderWidth: 3,
    backgroundColor: '#FFFFFF', // shows while the image loads; hidden once it covers
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.25,
    shadowRadius: 5,
    shadowOffset: { width: 0, height: 3 },
    elevation: 5,
  },
  // borderRadius on the image itself clips its corners (no overflow:hidden, so
  // the bubble's shadow still renders on iOS).
  photo: { width: '100%', height: '100%', borderRadius: 999 },
  tail: {
    width: 0,
    height: 0,
    borderLeftWidth: 6,
    borderRightWidth: 6,
    borderTopWidth: 9,
    borderLeftColor: 'transparent',
    borderRightColor: 'transparent',
    marginTop: -2, // tuck the tail under the ring
  },

  // Fallback teardrop (no poster).
  teardrop: {
    // Rounded on three corners, sharp bottom-left → a teardrop once rotated 45°.
    borderTopLeftRadius: 999,
    borderTopRightRadius: 999,
    borderBottomRightRadius: 999,
    borderBottomLeftRadius: 4,
    transform: [{ rotate: '45deg' }],
    borderWidth: 2.5,
    borderColor: '#FFFFFF',
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.2,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 3 },
    elevation: 4,
  },
  // Counter-rotate the glyph so the text reads upright inside the rotated pin.
  priceLabel: {
    transform: [{ rotate: '-45deg' }],
    color: '#FFFFFF',
    fontSize: 11,
    fontWeight: '800',
    letterSpacing: -0.5,
  },
});
