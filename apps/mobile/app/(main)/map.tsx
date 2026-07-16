import { Ionicons } from '@expo/vector-icons';
import BottomSheet, { BottomSheetView } from '@gorhom/bottom-sheet';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import MapView, { PROVIDER_DEFAULT, type Region } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useListMembership } from '@/api/hooks/useLists';
import { useMapPlaces } from '@/api/hooks/useMapPlaces';
import type { MapPin } from '@/api/places';
import type { SharePlace } from '@/api/shares';
import { ClusterMarker } from '@/components/map/cluster-marker';
import { FilterBar } from '@/components/map/filter-bar';
import { PlaceMarker } from '@/components/map/place-marker';
import { PlaceSheet } from '@/components/map/place-sheet';
import { QuickShareModal } from '@/components/map/quick-share';
import { SaveToListSheet } from '@/components/place/save-to-list';
import { buildClusterIndex, clusterExpansionZoom, clusterItems } from '@/lib/cluster';
import { bboxToRegion, regionToBbox, zoomBand, zoomFromRegion } from '@/lib/geo';
import { useT } from '@/i18n';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';
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

// Below this zoom band a lone place renders as a small dot (Google-style);
// at/above it the full photo bubble + name is shown. Set at neighborhood zoom
// so a lone place reveals its photo well before street level; wider views stay
// dots. Density itself is handled by clustering, so dots only stand in for
// singletons.
const DETAIL_BAND = 13;

export default function MapScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const params = useLocalSearchParams<{ lat?: string; lng?: string; list?: string; listName?: string }>();
  const setList = useMapStore((s) => s.setList);
  const activeList = useMapStore((s) => s.filters.list);

  // "View on map" from a list deep-links here with ?list=&listName= — apply it
  // as the map's list filter once (then it lives in the map store).
  useEffect(() => {
    if (params.list && params.listName) setList({ id: params.list, name: params.listName });
  }, [params.list, params.listName, setList]);

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
  // Latest settled region — drives the zoom buttons (the map is uncontrolled).
  const regionRef = useRef<Region>(initialRegion);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Timestamp of the last marker tap. Some react-native-maps builds also fire
  // the MapView's own onPress for a marker tap, which would immediately
  // deselect the pin we just opened — so the background tap ignores presses
  // that land right after a marker press.
  const lastMarkerPressAt = useRef(0);
  // The region that drives fetching — updated only on settle (debounced), never
  // per gesture frame, and never the MapView's own region prop (uncontrolled).
  const [queryRegion, setQueryRegion] = useState<Region>(initialRegion);

  const filters = useMapStore((s) => s.filters);
  const selected = useMapStore((s) => s.selected);
  const select = useMapStore((s) => s.select);
  const authed = useSessionStore((s) => s.status === 'authed');

  // The home map is the viewer's OWN places (T-071 personal model): authed →
  // `filter=mine` (shared ∪ saved), unless a saved list is the active scope
  // (its places are already mine). Guests have no personal collection, so they
  // browse the public map. Derived here (not stored) so it always tracks auth.
  const effectiveFilters = useMemo(
    () => ({ ...filters, filter: filters.list ? null : authed ? ('mine' as const) : null }),
    [filters, authed],
  );

  // Quick "add from a link" popup — paste a link/caption, and on publish fly the
  // map to the new pin without leaving the screen.
  const [quickOpen, setQuickOpen] = useState(false);
  // The pin whose "save to a list" sheet is open (T-073); authed viewers only.
  const [saveFor, setSaveFor] = useState<string | null>(null);

  const { data, isFetching } = useMapPlaces(queryRegion, effectiveFilters);

  // With a saved list as the active scope, a tapped pin is already in that list,
  // so the sheet's action removes it from THAT list only (T-073 follow-up) —
  // the map reaches here only for the viewer's own lists, so this is owner-safe.
  const { remove: removeFromList } = useListMembership();
  const onRemoveFromList = useCallback(
    (pinId: string) => {
      if (!activeList) return;
      Alert.alert(
        t('map.removeFromList.confirm.title', { name: activeList.name }),
        t('map.removeFromList.confirm.message'),
        [
          { text: t('common.cancel'), style: 'cancel' },
          {
            text: t('map.removeFromList.confirm.cta'),
            style: 'destructive',
            onPress: () => {
              removeFromList.mutate({ listId: activeList.id, placeId: pinId });
              select(null); // close the sheet; the pin drops when the map refetches
            },
          },
        ],
      );
    },
    [activeList, removeFromList, select, t],
  );

  const onRegionChangeComplete = useCallback((region: Region) => {
    regionRef.current = region;
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => setQueryRegion(region), 400);
  }, []);

  // On-screen zoom controls (Apple Maps has none): factor 0.5 zooms in, 2 out.
  // Deltas are clamped so the map can't zoom past street level or out past the
  // whole world.
  const zoomBy = useCallback((factor: number) => {
    const r = regionRef.current;
    const latitudeDelta = Math.min(Math.max(r.latitudeDelta * factor, 0.0025), 140);
    const longitudeDelta = Math.min(Math.max(r.longitudeDelta * factor, 0.0025), 140);
    const next = { latitude: r.latitude, longitude: r.longitude, latitudeDelta, longitudeDelta };
    regionRef.current = next;
    mapRef.current?.animateToRegion(next, 220);
  }, []);

  // Band + bbox for the *rendered* frame (from queryRegion, so it tracks fetches).
  const band = zoomBand(zoomFromRegion(queryRegion));
  const clientClustered = band >= CLIENT_CLUSTER_BAND;
  // Show full photo markers only when zoomed in close; otherwise dots.
  const detailed = band >= DETAIL_BAND;

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
      lastMarkerPressAt.current = Date.now();
      const pin = pinsRef.current.find((p) => p.id === id);
      if (pin) select(pin);
    },
    [select],
  );

  const onServerClusterPress = useCallback((id: string) => {
    lastMarkerPressAt.current = Date.now();
    const server = clustersRef.current?.find((cl) => cl.cluster_id === id);
    if (server) {
      mapRef.current?.animateToRegion(bboxToRegion(server.expand.bbox), 350);
    }
  }, []);

  // A quick-share published → fly to its pin. Animating settles the region,
  // which (debounced) refetches the viewport so the fresh pin renders.
  const onQuickPublished = useCallback((place: SharePlace) => {
    mapRef.current?.animateToRegion(
      { latitude: place.lat, longitude: place.lng, latitudeDelta: 0.02, longitudeDelta: 0.02 },
      350,
    );
  }, []);

  const onClientClusterPress = useCallback((clusterId: string) => {
    lastMarkerPressAt.current = Date.now();
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
        onPress={(e) => {
          // Ignore the map tap that some builds emit alongside a marker press
          // (would deselect the just-opened pin). Guard by action + recency.
          if (e?.nativeEvent?.action === 'marker-press') return;
          if (Date.now() - lastMarkerPressAt.current < 350) return;
          select(null);
        }}
        showsUserLocation
        showsMyLocationButton={false}
        // Hide Apple's own POI pins/labels — they cluttered the map and were
        // easy to mistake for (and tap instead of) Reelmap's own pins.
        showsPointsOfInterests={false}
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
                  detailed={detailed}
                  onPress={onPinPress}
                />
              ),
            )
          : pins.map((pin) => (
              <PlaceMarker
                key={pin.id}
                pin={pin}
                selected={selected?.id === pin.id}
                detailed={detailed}
                onPress={onPinPress}
              />
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
            accessibilityLabel={t('map.quickAdd')}
            onPress={() => setQuickOpen(true)}
            style={styles.searchButton}
          >
            <Ionicons name="add" size={24} color={c.primary} />
          </Pressable>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('map.search')}
            onPress={() => router.push('/search')}
            style={styles.searchButton}
          >
            <Ionicons name="search" size={20} color={c.primary} />
          </Pressable>
        </View>
        {activeList ? (
          <View style={styles.listBanner}>
            <Ionicons name="bookmark" size={14} color={c.onPrimary} />
            <Text style={styles.listBannerText} numberOfLines={1}>
              {activeList.name}
            </Text>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('map.clearList')}
              onPress={() => setList(null)}
              hitSlop={8}
            >
              <Ionicons name="close" size={16} color={c.onPrimary} />
            </Pressable>
          </View>
        ) : data?.truncated ? (
          <View style={styles.zoomChip}>
            <Text style={styles.zoomChipText}>{t('map.zoomIn')}</Text>
          </View>
        ) : null}
      </SafeAreaView>

      {/* Zoom controls (bottom-right) — Apple Maps has none of its own. */}
      <SafeAreaView edges={['bottom']} style={styles.zoomControls} pointerEvents="box-none">
        <View style={styles.zoomStack}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('map.zoomInLabel')}
            onPress={() => zoomBy(0.5)}
            style={({ pressed }) => [styles.zoomBtn, styles.zoomBtnTop, pressed && styles.zoomBtnPressed]}
          >
            <Ionicons name="add" size={24} color={c.text} />
          </Pressable>
          <View style={styles.zoomDivider} />
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('map.zoomOutLabel')}
            onPress={() => zoomBy(2)}
            style={({ pressed }) => [styles.zoomBtn, pressed && styles.zoomBtnPressed]}
          >
            <Ionicons name="remove" size={24} color={c.text} />
          </Pressable>
        </View>
      </SafeAreaView>

      <PreviewSheet
        pin={selected}
        onClose={() => select(null)}
        onViewPlace={(id) => {
          select(null);
          router.push({ pathname: '/place/[slug]', params: { slug: id } });
        }}
        // In a list scope, the pin action removes from that list; otherwise it
        // saves to a list. Both are authed-only.
        onSave={authed && !activeList ? (id) => setSaveFor(id) : undefined}
        onRemoveFromList={authed && activeList ? onRemoveFromList : undefined}
      />

      {/* Mounted only while open so each session starts fresh (no stale share). */}
      {quickOpen ? (
        <QuickShareModal visible onClose={() => setQuickOpen(false)} onPublished={onQuickPublished} />
      ) : null}

      {/* Save-to-list for a tapped pin (T-073). */}
      {saveFor ? <SaveToListSheet placeId={saveFor} visible onClose={() => setSaveFor(null)} /> : null}
    </View>
  );
}

/** The gorhom bottom sheet, opened/closed by the selected pin. */
function PreviewSheet({
  pin,
  onClose,
  onViewPlace,
  onSave,
  onRemoveFromList,
}: {
  pin: MapPin | null;
  onClose: () => void;
  onViewPlace: (id: string) => void;
  onSave?: (id: string) => void;
  onRemoveFromList?: (id: string) => void;
}) {
  const sheetRef = useRef<BottomSheet>(null);
  const snapPoints = useMemo(() => ['32%'], []);

  return (
    <BottomSheet ref={sheetRef} index={pin ? 0 : -1} snapPoints={snapPoints} enablePanDownToClose onClose={onClose}>
      <BottomSheetView>
        {pin ? (
          <PlaceSheet pin={pin} onViewPlace={onViewPlace} onSave={onSave} onRemoveFromList={onRemoveFromList} />
        ) : null}
      </BottomSheetView>
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
    listBanner: {
      alignSelf: 'center',
      marginTop: 8,
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      maxWidth: '80%',
      backgroundColor: c.primary,
      paddingHorizontal: 14,
      paddingVertical: 8,
      borderRadius: 999,
    },
    listBannerText: { color: c.onPrimary, fontSize: 13, fontWeight: '700', flexShrink: 1 },
    zoomControls: { position: 'absolute', right: 0, bottom: 0, padding: 16, alignItems: 'flex-end' },
    zoomStack: {
      backgroundColor: c.surface,
      borderRadius: 14,
      overflow: 'hidden',
      shadowColor: '#000',
      shadowOpacity: 0.18,
      shadowRadius: 6,
      shadowOffset: { width: 0, height: 2 },
      elevation: 3,
    },
    zoomBtn: { width: 46, height: 44, alignItems: 'center', justifyContent: 'center' },
    zoomBtnTop: {},
    zoomBtnPressed: { backgroundColor: c.primarySoft },
    zoomDivider: { height: StyleSheet.hairlineWidth, backgroundColor: c.border },
  });
