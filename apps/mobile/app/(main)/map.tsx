import { Ionicons } from '@expo/vector-icons';
import BottomSheet, { BottomSheetView } from '@gorhom/bottom-sheet';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import MapView, { PROVIDER_DEFAULT, type Region } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useMapPlaces } from '@/api/hooks/useMapPlaces';
import type { MapPin } from '@/api/places';
import { ClusterMarker } from '@/components/map/cluster-marker';
import { FilterBar } from '@/components/map/filter-bar';
import { PlaceMarker } from '@/components/map/place-marker';
import { PlaceSheet } from '@/components/map/place-sheet';
import { buildClusterIndex, clusterExpansionZoom, clusterItems } from '@/lib/cluster';
import { bboxToRegion, regionToBbox, zoomBand, zoomFromRegion } from '@/lib/geo';
import { useMapStore } from '@/stores/map';
import { type Palette, useColors } from '@/theme/colors';

// Default viewport (Montevideo — where the seed/demo data lives) until the user
// pans. A place-detail "open in map" push overrides it via lat/lng params.
const DEFAULT_REGION: Region = {
  latitude: -34.9,
  longitude: -56.16,
  latitudeDelta: 0.15,
  longitudeDelta: 0.15,
};

// Client-side supercluster kicks in once the server stops clustering (§4.1).
const CLIENT_CLUSTER_BAND = 15;

export default function MapScreen() {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const params = useLocalSearchParams<{ lat?: string; lng?: string }>();

  const initialRegion = useMemo<Region>(() => {
    const lat = Number(params.lat);
    const lng = Number(params.lng);
    // Both params must be present AND finite — `Number('')` is 0, which would
    // otherwise center an lat-only push on longitude 0 (the Gulf of Guinea).
    const bothPresent = (params.lat ?? '') !== '' && (params.lng ?? '') !== '';
    if (bothPresent && Number.isFinite(lat) && Number.isFinite(lng)) {
      return { latitude: lat, longitude: lng, latitudeDelta: 0.02, longitudeDelta: 0.02 };
    }
    return DEFAULT_REGION;
  }, [params.lat, params.lng]);

  const mapRef = useRef<MapView>(null);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  // The region that drives fetching — updated only on settle (debounced), never
  // per gesture frame, and never the MapView's own region prop (uncontrolled).
  const [queryRegion, setQueryRegion] = useState<Region>(initialRegion);

  const filters = useMapStore((s) => s.filters);
  const selected = useMapStore((s) => s.selected);
  const select = useMapStore((s) => s.select);

  const { data, isFetching } = useMapPlaces(queryRegion, filters);

  const onRegionChangeComplete = useCallback((region: Region) => {
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => setQueryRegion(region), 400);
  }, []);

  // Band + bbox for the *rendered* frame (from queryRegion, so it tracks fetches).
  const band = zoomBand(zoomFromRegion(queryRegion));
  const clientClustered = band >= CLIENT_CLUSTER_BAND;

  // Client supercluster only at high zoom; the index rebuilds only when the
  // pin set changes (O(n log n), never per frame).
  const pins = useMemo(() => data?.pins ?? [], [data?.pins]);
  const index = useMemo(() => (clientClustered ? buildClusterIndex(pins) : null), [clientClustered, pins]);
  // Recompute the clustered items whenever the *actual* viewport changes (each
  // settle), not just when the quantized fetch key changes — otherwise a
  // sub-cell pan reveals a strip whose (already-fetched) pins would be filtered
  // out by a stale bbox and blank until the next cell crossing.
  const rawBboxKey = regionToBbox(queryRegion).join(',');
  const clientItems = useMemo(
    () => (index ? clusterItems(index, regionToBbox(queryRegion), band) : null),
    // eslint-disable-next-line react-hooks/exhaustive-deps -- rawBboxKey stands in for the region object
    [index, rawBboxKey, band],
  );

  // Refs hold the latest fetched data so the marker press handlers can stay
  // reference-stable across fetches. react-query returns a NEW pins/clusters
  // array each fetch; capturing them in useCallback deps would recreate the
  // handlers every settle → defeat PlaceMarker's `onPress`-identity memo → all
  // markers re-render on every fetch. `select` is a stable zustand action.
  // Refs are synced in an effect (never written during render).
  const pinsRef = useRef(pins);
  const clustersRef = useRef(data?.clusters);
  const indexRef = useRef(index);
  const clientItemsRef = useRef(clientItems);
  useEffect(() => {
    pinsRef.current = pins;
    clustersRef.current = data?.clusters;
    indexRef.current = index;
    clientItemsRef.current = clientItems;
  }, [pins, data?.clusters, index, clientItems]);

  const onPinPress = useCallback(
    (id: string) => {
      const pin = pinsRef.current.find((p) => p.id === id);
      if (pin) select(pin);
    },
    [select],
  );

  const onServerClusterPress = useCallback((id: string) => {
    const server = clustersRef.current?.find((cl) => cl.cluster_id === id);
    if (server) {
      mapRef.current?.animateToRegion(bboxToRegion(server.expand.bbox), 350);
    }
  }, []);

  const onClientClusterPress = useCallback((clusterId: string) => {
    const idx = indexRef.current;
    if (!idx) return;
    const item = clientItemsRef.current?.find((it) => it.kind === 'cluster' && String(it.id) === clusterId);
    if (item && item.kind === 'cluster') {
      const zoom = clusterExpansionZoom(idx, Number(clusterId));
      // Latitude spans 180°, longitude 360° — halve the vertical delta so the
      // expanded region isn't ~2× too tall.
      const span = 360 / 2 ** zoom;
      mapRef.current?.animateToRegion(
        { latitude: item.lat, longitude: item.lng, latitudeDelta: span / 2, longitudeDelta: span },
        350,
      );
    }
  }, []);

  return (
    <View style={styles.container}>
      <MapView
        ref={mapRef}
        provider={PROVIDER_DEFAULT}
        style={StyleSheet.absoluteFill}
        initialRegion={initialRegion}
        onRegionChangeComplete={onRegionChangeComplete}
        onPress={() => select(null)}
        showsUserLocation
        showsMyLocationButton={false}
      >
        {/* Server clusters (below zoom 15). */}
        {data?.clusters.map((cl) => (
          <ClusterMarker
            key={`s:${cl.cluster_id}:${cl.count}`}
            id={cl.cluster_id}
            lat={cl.lat}
            lng={cl.lng}
            count={cl.count}
            onPress={onServerClusterPress}
          />
        ))}

        {/* Pins: client-clustered at high zoom, else rendered directly. */}
        {clientClustered && clientItems
          ? clientItems.map((item) =>
              item.kind === 'cluster' ? (
                <ClusterMarker
                  key={`c:${item.id}:${item.count}`}
                  id={String(item.id)}
                  lat={item.lat}
                  lng={item.lng}
                  count={item.count}
                  onPress={onClientClusterPress}
                />
              ) : (
                <PlaceMarker
                  key={item.pin.id}
                  pin={item.pin}
                  selected={selected?.id === item.pin.id}
                  onPress={onPinPress}
                />
              ),
            )
          : pins.map((pin) => (
              <PlaceMarker key={pin.id} pin={pin} selected={selected?.id === pin.id} onPress={onPinPress} />
            ))}
      </MapView>

      {/* Overlays above the map — do not re-render the MapView subtree. */}
      <SafeAreaView edges={['top']} style={styles.overlayTop} pointerEvents="box-none">
        <FilterBar />
        <View style={styles.headerRow} pointerEvents="box-none">
          {isFetching ? (
            <View style={styles.badge}>
              <ActivityIndicator size="small" color={c.primary} />
            </View>
          ) : null}
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Search"
            onPress={() => router.push('/search')}
            style={styles.searchButton}
          >
            <Ionicons name="search" size={20} color={c.primary} />
          </Pressable>
        </View>
        {data?.truncated ? (
          <View style={styles.zoomChip}>
            <Text style={styles.zoomChipText}>Zoom in for more places</Text>
          </View>
        ) : null}
      </SafeAreaView>

      <PreviewSheet
        pin={selected}
        onClose={() => select(null)}
        onViewPlace={(id) => {
          select(null);
          router.push({ pathname: '/place/[slug]', params: { slug: id } });
        }}
      />
    </View>
  );
}

/** The gorhom bottom sheet, opened/closed by the selected pin. */
function PreviewSheet({
  pin,
  onClose,
  onViewPlace,
}: {
  pin: MapPin | null;
  onClose: () => void;
  onViewPlace: (id: string) => void;
}) {
  const sheetRef = useRef<BottomSheet>(null);
  const snapPoints = useMemo(() => ['32%'], []);

  return (
    <BottomSheet ref={sheetRef} index={pin ? 0 : -1} snapPoints={snapPoints} enablePanDownToClose onClose={onClose}>
      <BottomSheetView>{pin ? <PlaceSheet pin={pin} onViewPlace={onViewPlace} /> : null}</BottomSheetView>
    </BottomSheet>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    container: { flex: 1, backgroundColor: c.background },
    overlayTop: { position: 'absolute', top: 0, left: 0, right: 0 },
    headerRow: { flexDirection: 'row', justifyContent: 'flex-end', alignItems: 'center', gap: 8, paddingHorizontal: 12 },
    badge: {
      backgroundColor: c.surface,
      borderRadius: 999,
      padding: 8,
      shadowColor: '#000',
      shadowOpacity: 0.15,
      shadowRadius: 4,
      elevation: 2,
    },
    searchButton: {
      backgroundColor: c.surface,
      width: 44,
      height: 44,
      borderRadius: 22,
      alignItems: 'center',
      justifyContent: 'center',
      shadowColor: '#000',
      shadowOpacity: 0.15,
      shadowRadius: 4,
      elevation: 2,
    },
    zoomChip: {
      alignSelf: 'center',
      marginTop: 8,
      backgroundColor: c.text,
      paddingHorizontal: 14,
      paddingVertical: 7,
      borderRadius: 999,
    },
    zoomChipText: { color: c.background, fontSize: 13, fontWeight: '600' },
  });
