import { memo, useEffect, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Marker } from 'react-native-maps';

import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

type Props = {
  id: string;
  lat: number;
  lng: number;
  /** The discount, already abbreviated for a marker ("20%", "$3.50", "×2"). */
  label: string;
  selected: boolean;
  onPress: (id: string) => void;
  /** Venue name, for screen readers — the visible label is the number alone. */
  accessibilityLabel?: string;
};

/**
 * An offer on the map: the discount itself, in a pill (T-047).
 *
 * **`tracksViewChanges` is the whole reason this is a component.**
 * react-native-maps rasterizes a marker's children into a bitmap, and with
 * tracking off it never redraws. Setting `tracksViewChanges={false}` outright —
 * which the offers map originally did inline — captures the bitmap before the
 * text has laid out, so the pin renders as an EMPTY pill: visible, tappable
 * nowhere useful, and completely silent about what it is.
 *
 * So tracking stays on until the content settles one frame later, then flips
 * false so that final true→false transition captures the laid-out pill once.
 * The same technique, and the same trap, as {@link PlaceMarker} — this is a
 * separate component only because the content differs (a discount pill, not a
 * place glyph with an async photo).
 */
function OfferMarkerBase({ id, lat, lng, label, selected, onPress, accessibilityLabel }: Props) {
  const c = useColors();
  const styles = makeStyles(c);

  // Re-arm tracking whenever the drawn content changes, and settle on the next
  // frame. The first mount is skipped: react-native-maps captures once on mount
  // regardless, so a synchronous label needs no extra pass.
  const [ready, setReady] = useState(true);
  const mounted = useRef(false);

  useEffect(() => {
    if (!mounted.current) {
      mounted.current = true;
      return;
    }
    setReady(false);
    const frame = requestAnimationFrame(() => setReady(true));

    return () => cancelAnimationFrame(frame);
  }, [label, selected]);

  return (
    <Marker
      identifier={id}
      coordinate={{ latitude: lat, longitude: lng }}
      // Bottom-centre: the pill's lower edge sits on the venue, the way a
      // pointer would. Without it the pill straddles the point and reads as
      // belonging to whatever is above it.
      anchor={ANCHOR}
      tracksViewChanges={!ready}
      onPress={() => onPress(id)}
      accessibilityLabel={accessibilityLabel ?? label}
      testID={`offer-marker-${id}`}
    >
      <View style={[styles.pin, selected && styles.pinSelected]}>
        <Text style={[styles.label, selected && styles.labelSelected]} numberOfLines={1}>
          {label}
        </Text>
      </View>
    </Marker>
  );
}

export const OfferMarker = memo(OfferMarkerBase);

const ANCHOR = { x: 0.5, y: 1 };

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    pin: {
      backgroundColor: c.primary,
      borderRadius: radius.pill,
      paddingHorizontal: space.sm,
      paddingVertical: space.xxs,
      borderWidth: 2,
      borderColor: c.surface,
      // A marker's bitmap is captured at its natural size; without a minimum the
      // shortest labels ("×2") shrink to a dot that is hard to hit.
      minWidth: MIN_PIN_WIDTH,
      alignItems: 'center',
    },
    pinSelected: { backgroundColor: c.text },
    label: { ...type.bodySm, fontFamily: fonts.display, color: c.onPrimary },
    labelSelected: { color: c.background },
  });

/** Wide enough to stay a comfortable tap target for a two-character label. */
const MIN_PIN_WIDTH = 44;
