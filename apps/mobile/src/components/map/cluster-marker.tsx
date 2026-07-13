import { memo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Marker } from 'react-native-maps';

type Props = {
  id: string;
  lat: number;
  lng: number;
  count: number;
  onPress: (id: string) => void;
};

const CLUSTER = '#208AEF';

/** A count bubble standing in for several nearby places; tap zooms to expand. */
function ClusterMarkerBase({ id, lat, lng, count, onPress }: Props) {
  // Grow the bubble with the count (log scale) so dense clusters read as bigger.
  const size = Math.min(64, 34 + Math.log10(Math.max(count, 1)) * 16);
  // Frozen bitmap — the caller keys the marker on the count, so a count change
  // remounts it (fresh raster) rather than needing per-frame tracking.
  return (
    <Marker
      identifier={`cluster:${id}`}
      coordinate={{ latitude: lat, longitude: lng }}
      tracksViewChanges={false}
      onPress={() => onPress(id)}
      accessibilityLabel={`Cluster of ${count} places`}
    >
      <View style={[styles.bubble, { width: size, height: size, borderRadius: size / 2 }]}>
        <Text style={styles.count}>{count}</Text>
      </View>
    </Marker>
  );
}

export const ClusterMarker = memo(
  ClusterMarkerBase,
  (prev, next) => prev.id === next.id && prev.count === next.count && prev.onPress === next.onPress,
);

const styles = StyleSheet.create({
  bubble: {
    backgroundColor: CLUSTER,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 3,
    borderColor: 'rgba(255,255,255,0.85)',
  },
  count: { color: '#FFFFFF', fontWeight: '800', fontSize: 14 },
});
