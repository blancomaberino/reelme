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
import { LocationBlockedHint, MapControls, useMapCamera } from '@/components/map/map-controls';
import { PlaceMarker } from '@/components/map/place-marker';
import { PlaceSheet } from '@/components/map/place-sheet';
import { QuickShareModal } from '@/components/map/quick-share';
import { SaveToListSheet } from '@/components/place/save-to-list';
import { buildClusterIndex, clusterExpansionZoom, clusterItems } from '@/lib/cluster';
import { bboxToRegion, regionToBbox, zoomBand, zoomFromRegion } from '@/lib/geo';
import {
  type InitialRegion,
  resolveInitialRegion,
  shouldCenterOnViewer,
  syncInitialRegion,
} from '@/lib/initial-region';
import { useT } from '@/i18n';
import { useRefreshViewerPosition, useViewerPosition } from '@/lib/use-viewer-position';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';
import { useViewportStore } from '@/stores/viewport';
import { type Palette, useColors } from '@/theme/colors';

// Client-side supercluster kicks in once the server stops clustering (§4.1).
const CLIENT_CLUSTER_BAND = 15;

// Below this zoom band a lone place renders as a small dot (Google-style);
// at/above it the full photo bubble + name is shown. Set at neighborhood zoom
// so a lone place reveals its photo well before street level; wider views stay
// dots. Density itself is handled by clustering, so dots only stand in for
// singletons.
const DETAIL_BAND = 13;

/**
 * Resolves WHERE the map opens before mounting the canvas (T-100).
 *
 * The MapView is uncontrolled — it reads `initialRegion` exactly once — so the
 * opening viewport has to be known at first render. A deep-linked lat/lng or a
 * remembered viewport is available synchronously, so those (the overwhelmingly
 * common cases) paint with no loading state at all. Only a true first launch,
 * where we ask the OS for permission and wait on a fix, shows the interim state
 * — and there, "finding your location" is a far better answer than flashing a
 * city the user has no connection to.
 */
export default function MapScreen() {
  const params = useLocalSearchParams<{ lat?: string; lng?: string; list?: string; listName?: string }>();

  // Resolved ONCE and then sticky. Deliberately not derived from a subscribed
  // `saved` — the store's `saved` changes on every map settle, and re-deriving
  // off it would re-render this wrapper (and hand MapCanvas a fresh `initial`)
  // on every pan, for a value the uncontrolled MapView read at mount and will
  // never read again.
  const [resolved, setResolved] = useState<InitialRegion | null>(() => {
    const { saved, hydrated } = useViewportStore.getState();
    return syncInitialRegion({ lat: params.lat, lng: params.lng, saved, hydrated });
  });
  // Subscribe to the hydration flip only — one re-render, not one per pan.
  const hydrated = useViewportStore((s) => s.hydrated);

  useEffect(() => {
    if (resolved || !hydrated) return;

    let active = true;
    const { saved } = useViewportStore.getState();
    void resolveInitialRegion({ lat: params.lat, lng: params.lng, saved }).then((next) => {
      if (active) setResolved(next);
    });
    return () => {
      active = false;
    };
  }, [resolved, hydrated, params.lat, params.lng]);

  if (!resolved) return <LocatingState />;

  return <MapCanvas initial={resolved} params={params} />;
}

/** Interim state while a first-launch location fix resolves. */
function LocatingState() {
  const c = useColors();
  const t = useT();
  // Its own two-rule sheet — building the map's full stylesheet for a spinner
  // would be ~25 wasted StyleSheet entries.
  const styles = useMemo(() => makeLocatingStyles(c), [c]);

  return (
    <View style={styles.root}>
      <ActivityIndicator color={c.primary} />
      <Text style={styles.label}>{t('map.locating')}</Text>
    </View>
  );
}

const makeLocatingStyles = (c: Palette) =>
  StyleSheet.create({
    root: {
      flex: 1,
      backgroundColor: c.background,
      alignItems: 'center',
      justifyContent: 'center',
      gap: 12,
    },
    label: { fontSize: 15, color: c.ink2 },
  });

function MapCanvas({
  initial,
  params,
}: {
  initial: InitialRegion;
  params: { lat?: string; lng?: string; list?: string; listName?: string };
}) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const setList = useMapStore((s) => s.setList);
  const activeList = useMapStore((s) => s.filters.list);
  const remember = useViewportStore((s) => s.remember);

  // "View on map" from a list deep-links here with ?list=&listName= — apply it
  // as the map's list filter once (then it lives in the map store).
  useEffect(() => {
    if (params.list && params.listName) setList({ id: params.list, name: params.listName });
  }, [params.list, params.listName, setList]);

  const initialRegion = initial.region;
  // A permanently-denied permission surfaced during first-launch resolution —
  // show the "enable it in Settings" hint once, unprompted, since the map is
  // silently framed on a fallback the user didn't choose.
  const [locateHint, setLocateHint] = useState(initial.permissionBlocked);

  // True once the user has actually manipulated the map (pan, zoom control,
  // cluster tap, locate, fly-to). Gates viewport persistence — see
  // onRegionChangeComplete.
  const interacted = useRef(false);

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

  // The viewer's own position, for VIEWER-RELATIVE data (T-156) — never a
  // prompt of its own: it reads a permission the first-launch resolve or the
  // "locate me" button already obtained, and yields null otherwise. Null means
  // the pins simply carry no distance and no open/closed cue, which is the
  // honest outcome, not a degraded one.
  const viewer = useViewerPosition();
  // "Locate me" owns a permission prompt; a grant there is a new answer to the
  // question `useViewerPosition` asked at mount and got "not allowed" to.
  const refreshViewer = useRefreshViewerPosition();

  const { data, fetchedAt, isFetching, isError, refetch } = useMapPlaces(
    queryRegion,
    effectiveFilters,
    viewer,
  );

  // A map with no pins has three causes and the user can only act on one of
  // them (T-103). Offline is the ConnectionBanner's job — it is app-wide and
  // there is nothing to retry. A genuine failure gets a chip here, because the
  // alternative is an empty map that looks exactly like "you have no places".
  // Only when there is nothing to show: cached pins beat an error notice.
  const showFetchError = isError && (data?.pins.length ?? 0) === 0 && (data?.clusters.length ?? 0) === 0;

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

  const markInteraction = useCallback(() => {
    interacted.current = true;
  }, []);

  /**
   * The single way this screen moves the map. Every caller is downstream of a
   * user action (zoom control, reset, locate, cluster tap, post-publish fly-to),
   * so each one counts as an interaction — routing them all through here is what
   * stops a future move from forgetting to mark itself.
   */
  /*
   * The camera is shared with the offers map (T-047). It owns the ref, the
   * clamped zoom steps, "reset to home", and the locate flow — so the two maps
   * cannot drift into disagreeing about what a zoom step or a reset means.
   */
  const camera = useMapCamera({
    initialRegion,
    initialUserRegion: initial.source === 'user' ? initial.region : null,
    onInteraction: markInteraction,
    onLocated: refreshViewer,
  });
  const { mapRef, moveMap } = camera;

  // Re-frame onto the viewer once, when they are near their own places (T-156).
  //
  // AFTER the first paint, not before it: the opening viewport must be known
  // synchronously or a returning user waits on the GPS, so the map opens where
  // they left it and this only improves the frame if a fix arrives. By
  // construction the move is small — `shouldCenterOnViewer` allows it only
  // inside CENTER_ON_VIEWER_RADIUS_M of the frame they left, so it reads as
  // "here you are", never as being yanked across the world.
  //
  // It moves with `persist: false`, and that flag is the whole point. A plain
  // `moveMap` marks an interaction, and an interaction is what makes the
  // resulting settle PERSIST — so re-framing through it saved a viewport the
  // user never chose. Worse, it was self-perpetuating: next launch the saved
  // frame is that box, the viewer is 0 m from it, so it re-framed and re-saved
  // again, and a user could never keep a wide city frame. That is the
  // "fallback-poisoning" bug T-100 fixed, re-entering through the one path that
  // bypasses its guard.
  //
  // The camera still records the region, because the zoom buttons step from its
  // idea of where the map is; only the PERSISTENCE is withheld.
  //
  // Three guards, all load-bearing:
  //  - `interacted.current` — the moment the user has touched the map, the
  //    viewport is THEIRS. A fix can land seconds after mount.
  //  - `selected` — a pin sheet is open, so the user is reading. Moving the map
  //    under it (and refetching a different viewport) is the same theft, and a
  //    marker tap does not set `interacted`.
  //  - `centred` — once only.
  const centred = useRef(false);
  useEffect(() => {
    if (centred.current) return;

    // ABANDONED, not deferred, the moment the user touches anything. The guards
    // used to merely skip, and `selected` is a dependency — so a viewer who
    // opened a pin before the fix landed got the re-frame when they DISMISSED
    // the sheet: the effect re-ran, the guards now passed, and the map slid 450
    // ms in response to a gesture whose meaning was "close this". Once the map
    // is theirs it stays theirs.
    if (interacted.current || selected) {
      centred.current = true;
      return;
    }

    if (!viewer) return;
    if (!shouldCenterOnViewer({ viewer, anchor: initialRegion, source: initial.source })) return;

    centred.current = true;
    // The viewer's position at the CURRENT zoom, not a hard-coded 0.02 box.
    // Someone who left the map showing all of Montevideo asked for that scale;
    // re-centring is an improvement, silently zooming them to two streets is not.
    const next = {
      latitude: viewer.latitude,
      longitude: viewer.longitude,
      latitudeDelta: initialRegion.latitudeDelta,
      longitudeDelta: initialRegion.longitudeDelta,
    };
    moveMap(next, 450, { persist: false });
  }, [viewer, initialRegion, initial.source, selected, moveMap]);

  const onRegionChangeComplete = useCallback(
    (region: Region) => {
      // Into the SHARED camera: the zoom buttons step from this, so a local
      // copy would leave them zooming out from wherever the map first opened.
      camera.rememberRegion(region);
      if (debounce.current) clearTimeout(debounce.current);
      // Persist ONLY a viewport the user actually moved to.
      //
      // onRegionChangeComplete also fires on the map's initial layout, with no
      // user action behind it. Persisting that saved whatever we happened to
      // open at — including a DEFAULT_REGION fallback nobody picked — and rung 2
      // of the resolve chain then beat the location rung on every later launch,
      // leaving the map stuck on the fallback city even after location was
      // granted. That is precisely the bug T-100 exists to fix, so the guard is
      // load-bearing.
      //
      // NOT gated on the event's `details.isGesture`: that flag is Android-only
      // (AIRMapManager.m builds an iOS payload containing `region` alone), and
      // its type is `isGesture?: boolean`, so relying on it typechecks happily
      // and then silently never persists anything on iOS. Verified on device.
      const userMoved = interacted.current;
      debounce.current = setTimeout(() => {
        setQueryRegion(region);
        if (userMoved) remember(region);
      }, 400);
    },
    [camera, remember],
  );

  // Drop a settle still waiting out its 400 ms window when the screen goes away
  // — otherwise leaving mid-pan fires `setQueryRegion` on an unmounted tree and
  // persists a viewport for a map the user has already navigated off.
  useEffect(
    () => () => {
      if (debounce.current) clearTimeout(debounce.current);
    },
    [],
  );

  /**
   * Marks the viewport as user-driven, so the settle it produces is remembered.
   * Wired to the map's own pan gesture; every programmatic move goes through
   * {@link moveMap}, which calls this too. Anything that does NOT go through
   * here — the mount layout settle above all — is never persisted.
   */

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

  const onServerClusterPress = useCallback(
    (id: string) => {
      lastMarkerPressAt.current = Date.now();
      const server = clustersRef.current?.find((cl) => cl.cluster_id === id);
      if (server) {
        moveMap(bboxToRegion(server.expand.bbox), 350);
      }
    },
    [moveMap],
  );

  // A quick-share published → center the map on the new pin (so it's framed when
  // the user returns) AND open its detail: you land on the place you just added
  // (T-076). For a multi-place post `place` is the primary. Animating settles the
  // region, which (debounced) refetches the viewport so the fresh pin renders.
  const onQuickPublished = useCallback(
    (place: SharePlace) => {
      moveMap(
        { latitude: place.lat, longitude: place.lng, latitudeDelta: 0.02, longitudeDelta: 0.02 },
        350,
      );
      router.push({ pathname: '/place/[slug]', params: { slug: place.id } });
    },
    [moveMap],
  );

  const onClientClusterPress = useCallback(
    (clusterId: string) => {
      lastMarkerPressAt.current = Date.now();
      const idx = indexRef.current;
      if (!idx) return;
      const item = clientItemsRef.current?.find((it) => it.kind === 'cluster' && String(it.id) === clusterId);
      if (item && item.kind === 'cluster') {
        const zoom = clusterExpansionZoom(idx, Number(clusterId));
        // Latitude spans 180°, longitude 360° — halve the vertical delta so the
        // expanded region isn't ~2× too tall.
        const span = 360 / 2 ** zoom;
        moveMap(
          { latitude: item.lat, longitude: item.lng, latitudeDelta: span / 2, longitudeDelta: span },
          350,
        );
      }
    },
    [moveMap],
  );

  return (
    <View style={styles.container}>
      <MapView
        ref={mapRef}
        provider={PROVIDER_DEFAULT}
        style={StyleSheet.absoluteFill}
        initialRegion={initialRegion}
        onRegionChangeComplete={onRegionChangeComplete}
        // A real pan is the primary signal that the viewport is the user's choice.
        onPanDrag={markInteraction}
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
          {/* The diner's way into nearby offers (T-047, screen #17). It lives
              here rather than in the tab bar because an offer is a geographic
              fact — "what's on near me" is the same question the map answers. */}
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('map.offers')}
            accessibilityHint={t('map.offers.hint')}
            onPress={() => router.push('/offers')}
            style={styles.searchButton}
            testID="map-offers"
          >
            <Ionicons name="pricetag-outline" size={20} color={c.primary} />
          </Pressable>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('map.quickAdd')}
            accessibilityHint={t('map.quickAdd.hint')}
            onPress={() => setQuickOpen(true)}
            style={styles.searchButton}
          >
            <Ionicons name="add" size={24} color={c.primary} />
          </Pressable>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('map.search')}
            accessibilityHint={t('map.search.hint')}
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
        ) : showFetchError ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${t('map.error')} — ${t('common.tryAgain')}`}
            onPress={() => void refetch()}
            style={styles.errorChip}
            testID="map-error-chip"
          >
            <Ionicons name="alert-circle-outline" size={14} color={c.onPrimary} />
            <Text style={styles.errorChipText}>{t('map.error')}</Text>
            <Text style={styles.errorChipAction}>{t('common.tryAgain')}</Text>
          </Pressable>
        ) : data?.truncated ? (
          <View style={styles.zoomChip}>
            <Text style={styles.zoomChipText}>{t('map.zoomIn')}</Text>
          </View>
        ) : null}

        {/* Location is permanently denied — the only fix is the OS settings
            page, so say so once and offer the jump. Dismissible: the map is
            fully usable without it. */}
        {locateHint || camera.locateBlocked ? (
          <LocationBlockedHint
            onDismiss={() => {
              setLocateHint(false);
              camera.setLocateBlocked(false);
            }}
          />
        ) : null}
      </SafeAreaView>

      <MapControls camera={camera} />

      <PreviewSheet
        pin={selected}
        // When these pins were fetched — the sheet's open/closed cue ages out on
        // it rather than repainting a stale "Abierto" from a persisted query.
        fetchedAt={fetchedAt}
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
  fetchedAt,
  onClose,
  onViewPlace,
  onSave,
  onRemoveFromList,
}: {
  pin: MapPin | null;
  fetchedAt: number;
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
          <PlaceSheet
            pin={pin}
            fetchedAt={fetchedAt}
            onViewPlace={onViewPlace}
            onSave={onSave}
            onRemoveFromList={onRemoveFromList}
          />
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
    // Same pinned-chip language as the zoom hint, in the danger tone.
    errorChip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      alignSelf: 'center',
      marginTop: 8,
      backgroundColor: c.danger,
      paddingHorizontal: 14,
      paddingVertical: 7,
      borderRadius: 999,
    },
    errorChipText: { color: c.onPrimary, fontSize: 13, fontWeight: '600' },
    errorChipAction: { color: c.onPrimary, fontSize: 13, fontWeight: '800', textDecorationLine: 'underline' },
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
    locateHint: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 12,
      marginTop: 8,
      marginHorizontal: 12,
      padding: 14,
      borderRadius: 14,
      backgroundColor: c.surface,
      borderWidth: 1,
      borderColor: c.border,
      shadowColor: '#000',
      shadowOpacity: 0.12,
      shadowRadius: 6,
      shadowOffset: { width: 0, height: 2 },
      elevation: 3,
    },
    locateHintBody: { flex: 1, gap: 4 },
    locateHintTitle: { fontSize: 15, fontWeight: '700', color: c.text },
    locateHintText: { fontSize: 13, color: c.ink2, lineHeight: 18 },
    locateHintCta: { fontSize: 14, fontWeight: '700', color: c.primary, marginTop: 4 },
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
