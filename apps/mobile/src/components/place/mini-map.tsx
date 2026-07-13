import { useMemo } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import MapView, { Marker, PROVIDER_DEFAULT } from 'react-native-maps';

import { type Palette, useColors } from '@/theme/colors';

type Props = {
  lat: number;
  lng: number;
  /** Tap-through target (navigates to the Map tab centered here). */
  onPress: () => void;
};

/**
 * A small, non-interactive map preview on the place detail screen (T-033). The
 * MapView itself is gesture-disabled and wrapped so it can't steal the parent
 * ScrollView's pan; an overlay Pressable provides the tap-through.
 */
export function MiniMap({ lat, lng, onPress }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={styles.wrap}>
      <View pointerEvents="none" style={StyleSheet.absoluteFill}>
        <MapView
          provider={PROVIDER_DEFAULT}
          style={StyleSheet.absoluteFill}
          pointerEvents="none"
          scrollEnabled={false}
          zoomEnabled={false}
          rotateEnabled={false}
          pitchEnabled={false}
          region={{ latitude: lat, longitude: lng, latitudeDelta: 0.01, longitudeDelta: 0.01 }}
        >
          <Marker coordinate={{ latitude: lat, longitude: lng }} tracksViewChanges={false} />
        </MapView>
      </View>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Open in map"
        onPress={onPress}
        style={StyleSheet.absoluteFill}
      />
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: {
      height: 160,
      borderRadius: 16,
      overflow: 'hidden',
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      backgroundColor: c.surface,
    },
  });
