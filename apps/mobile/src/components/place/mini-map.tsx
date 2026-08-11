import { useMemo } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import MapView, { PROVIDER_DEFAULT } from 'react-native-maps';

import { type MarkerPlace, PlaceMarker } from '@/components/map/place-marker';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

type Props = {
  /**
   * The place itself, not bare coordinates — so this preview draws the SAME pin
   * as every other map in the app. It used to take only lat/lng, which left it
   * no choice but the platform's default red marker: one of five surfaces that
   * had quietly grown its own.
   */
  place: MarkerPlace;
  /** Tap-through target (navigates to the Map tab centered here). */
  onPress: () => void;
};

/** The map layer is pointerEvents="none" — the wrapping Pressable owns the tap. */
const NO_MARKER_PRESS = () => {};

/**
 * A small, non-interactive map preview on the place detail screen (T-033). The
 * MapView itself is gesture-disabled and wrapped so it can't steal the parent
 * ScrollView's pan; an overlay Pressable provides the tap-through.
 */
export function MiniMap({ place, onPress }: Props) {
  const c = useColors();
  const t = useT();
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
          region={{ latitude: place.lat, longitude: place.lng, latitudeDelta: 0.01, longitudeDelta: 0.01 }}
        >
          <PlaceMarker
            pin={place}
            selected={false}
            detailed
            // No label: the place's name is on screen directly above this map.
            showName={false}
            onPress={NO_MARKER_PRESS}
          />
        </MapView>
      </View>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t('place.openInMap')}
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
