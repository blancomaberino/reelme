import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import MapView, { PROVIDER_DEFAULT, type Region } from 'react-native-maps';

import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

/** Zoom span for the adjuster — tight enough to place a pin precisely. */
const PIN_DELTA = 0.01;

/**
 * Pan-under-fixed-crosshair pin placement (04 §7): the marker is a static
 * overlay dead-center, the map moves beneath it, and the settled region's center
 * is the chosen coordinate. This beats a draggable marker on touch — no fat-finger
 * offset, and the target never hides under the finger.
 */
export function PinAdjuster({
  lat,
  lng,
  onChange,
}: {
  lat: number;
  lng: number;
  onChange: (lat: number, lng: number) => void;
}) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  // Uncontrolled: seed once from the incoming coordinate; the map owns the region
  // thereafter and only reports back the settled center (never per-frame).
  const [initialRegion] = useState<Region>(() => ({
    latitude: lat,
    longitude: lng,
    latitudeDelta: PIN_DELTA,
    longitudeDelta: PIN_DELTA,
  }));

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{t('review.pin.title')}</Text>
      <View style={styles.mapBox}>
        <MapView
          testID="pin-map"
          style={StyleSheet.absoluteFill}
          provider={PROVIDER_DEFAULT}
          initialRegion={initialRegion}
          onRegionChangeComplete={(r: Region) => onChange(r.latitude, r.longitude)}
          pitchEnabled={false}
          rotateEnabled={false}
          toolbarEnabled={false}
        />
        {/* Fixed crosshair — sits above the map, offset up so the pin's TIP marks
            center. pointerEvents none so pans pass through to the map. */}
        <View pointerEvents="none" style={styles.crosshair}>
          <Ionicons name="location" size={40} color={c.primary} />
        </View>
      </View>
      <Text style={styles.hint}>{t('review.pin.hint')}</Text>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { gap: 8 },
    label: { fontFamily: fonts.display, fontSize: 16, fontWeight: '700', color: c.text },
    mapBox: {
      height: 220,
      borderRadius: 16,
      overflow: 'hidden',
      borderWidth: 1,
      borderColor: c.border,
      backgroundColor: c.surface2,
    },
    crosshair: {
      position: 'absolute',
      top: 0,
      left: 0,
      right: 0,
      bottom: 0,
      alignItems: 'center',
      justifyContent: 'center',
      // Lift the glyph so its pointed tip — not its center — lands on the map center.
      marginBottom: 40,
    },
    hint: { fontSize: 13, color: c.muted },
  });
