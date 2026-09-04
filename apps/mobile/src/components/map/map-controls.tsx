import { Ionicons } from '@expo/vector-icons';
import { useCallback, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import type MapView from 'react-native-maps';

import { useT } from '@/i18n';
import type { Region } from '@/lib/geo';
import { DEFAULT_REGION, locateUser } from '@/lib/initial-region';
import { openLocationSettings } from '@/lib/location';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * The camera every Reelmap map is driven by (T-047).
 *
 * Extracted from the home map so the offers map is not a second, worse map.
 * Two maps that both let you zoom but disagree about how far, or about where
 * "reset" goes, teach the user that the controls mean different things
 * depending on the screen — which is the same as having no controls.
 *
 * The MapView stays UNCONTROLLED (it reads `initialRegion` once); every move
 * goes through `moveMap`, which is also the single place an interaction gets
 * marked. A move that forgets to mark itself is a viewport the app quietly
 * stops remembering.
 */
export function useMapCamera(input: {
  initialRegion: Region;
  /** The position resolved at startup, if it was the user's own. */
  initialUserRegion?: Region | null;
  /** Called on every programmatic move, so a screen can persist the viewport. */
  onInteraction?: () => void;
  /**
   * Called when "locate me" successfully obtains a fix — which is also the
   * moment a first-time grant happens. A screen that measures distances from
   * the viewer's position uses this to re-ask for it; without it, granting here
   * flies the map to the user and leaves every pin distance-less until the app
   * restarts (T-156).
   */
  onLocated?: () => void;
}) {
  const t = useT();
  const mapRef = useRef<MapView>(null);
  const regionRef = useRef<Region>(input.initialRegion);
  // Where "locate me" last put us. Null until it is tapped — the caller's own
  // fix is the other source, consulted at call time below.
  const userRegionRef = useRef<Region | null>(null);

  const [locating, setLocating] = useState(false);
  const [locateBlocked, setLocateBlocked] = useState(false);

  const { onInteraction, onLocated } = input;

  /**
   * Move the camera. Every programmatic move goes through here, including the
   * ones that must NOT be remembered.
   *
   * `persist: false` is for a move the USER did not ask for — the one-time
   * re-frame onto a viewer's own position at startup (T-156). Marking that as an
   * interaction persists a viewport nobody chose, so the next cold start opens
   * on a box the app picked for itself and then treats as the user's answer.
   * Bypassing `moveMap` for those was the alternative, and it made the screen a
   * second owner of the camera; the flag keeps this hook the only one.
   */
  const moveMap = useCallback(
    (region: Region, duration: number, options?: { persist?: boolean }) => {
      if (options?.persist !== false) onInteraction?.();
      regionRef.current = region;
      mapRef.current?.animateToRegion(region, duration);
    },
    [onInteraction],
  );

  // Factor 0.5 zooms in, 2 out. Deltas are clamped so the map cannot zoom past
  // street level or out past the whole world.
  const zoomBy = useCallback(
    (factor: number) => {
      const r = regionRef.current;
      const latitudeDelta = Math.min(Math.max(r.latitudeDelta * factor, 0.0025), 140);
      const longitudeDelta = Math.min(Math.max(r.longitudeDelta * factor, 0.0025), 140);
      moveMap({ latitude: r.latitude, longitude: r.longitude, latitudeDelta, longitudeDelta }, 220);
    },
    [moveMap],
  );

  /*
   * "Home" is the user's own position when we have one this session, and only
   * otherwise the seed city — resetting to a city the user has never visited is
   * not a reset.
   *
   * Both sources are read AT CALL TIME rather than captured at mount: the
   * caller's fix is a query that often resolves after the first render, and a
   * snapshot left "reset" pointing at the seed city for the whole session. A
   * tap on "locate me" is the fresher answer, so it wins.
   */
  const initialUserRegion = input.initialUserRegion ?? null;
  const resetView = useCallback(() => {
    moveMap(userRegionRef.current ?? initialUserRegion ?? DEFAULT_REGION, 350);
  }, [moveMap, initialUserRegion]);

  /**
   * "Locate me". Prompts on first tap, flies to the user on success, and
   * explains itself on every failure — a permanently-denied permission offers
   * the Settings deep link, a missing fix says so and stays tappable. Never a
   * silent no-op.
   */
  const locate = useCallback(async () => {
    setLocating(true);
    try {
      const result = await locateUser();
      if (result.ok) {
        userRegionRef.current = result.region;
        moveMap(result.region, 350);
        setLocateBlocked(false);
        onLocated?.();
        return;
      }
      if (result.reason === 'blocked') {
        setLocateBlocked(true);
        return;
      }
      if (result.reason === 'unavailable') {
        Alert.alert(t('map.location.unavailable'));
      }
      // 'denied' with canAskAgain — the user dismissed the OS prompt. They know
      // what they did; re-explaining it would be nagging.
    } finally {
      setLocating(false);
    }
  }, [moveMap, onLocated, t]);

  /** Record a region the map settled on itself (a pan), without animating. */
  const rememberRegion = useCallback((region: Region) => {
    regionRef.current = region;
  }, []);

  return {
    mapRef,
    regionRef,
    userRegionRef,
    moveMap,
    zoomBy,
    resetView,
    locate,
    locating,
    locateBlocked,
    setLocateBlocked,
    rememberRegion,
  };
}

export type MapCamera = ReturnType<typeof useMapCamera>;

/**
 * Some react-native-maps builds fire the MapView's OWN `onPress` alongside a
 * marker press. A background handler that clears the selection therefore
 * deselects the pin the user just opened, in the same frame — so the tap reads
 * as doing nothing at all.
 *
 * Guarded two ways because neither is reliable alone: the event's `action`
 * (present on the builds that label it) and recency against the last marker
 * press (for the ones that don't). Extracted from the home map, where this was
 * found the first time — the offers map then shipped a second background
 * handler without it and its pins were dead on tap.
 */
export function useMapSelection<T>() {
  const [selected, setSelected] = useState<T | null>(null);
  const lastMarkerPressAt = useRef(0);

  const selectFromMarker = useCallback((value: T) => {
    lastMarkerPressAt.current = Date.now();
    setSelected(value);
  }, []);

  /** Call from a marker handler that moves the map instead of selecting. */
  const noteMarkerPress = useCallback(() => {
    lastMarkerPressAt.current = Date.now();
  }, []);

  const onBackgroundPress = useCallback((event?: { nativeEvent?: { action?: string } }) => {
    if (event?.nativeEvent?.action === 'marker-press') return;
    if (Date.now() - lastMarkerPressAt.current < MARKER_PRESS_GRACE_MS) return;
    setSelected(null);
  }, []);

  return { selected, setSelected, selectFromMarker, noteMarkerPress, onBackgroundPress };
}

/** Long enough to cover the paired map press, short enough to feel immediate. */
const MARKER_PRESS_GRACE_MS = 350;

/**
 * The control stack, bottom-right: locate · reset · zoom in · zoom out.
 *
 * Apple Maps ships no zoom buttons of its own, and a map with pins the user is
 * meant to compare is a map they will want to step through a level at a time
 * rather than pinch at.
 */
export function MapControls({ camera }: { camera: MapCamera }) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <SafeAreaView edges={['bottom']} style={styles.controls} pointerEvents="box-none">
      <View style={styles.stack}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('map.locateLabel')}
          accessibilityHint={t('map.locateLabel.hint')}
          accessibilityState={{ busy: camera.locating, disabled: camera.locating }}
          // Disabled while in flight: a double-tap would otherwise fire two
          // permission prompts / GPS requests for one intent.
          disabled={camera.locating}
          onPress={() => void camera.locate()}
          style={({ pressed }) => [styles.btn, pressed && styles.btnPressed]}
          testID="map-locate"
        >
          {camera.locating ? (
            <ActivityIndicator size="small" color={c.primary} />
          ) : (
            <Ionicons name="locate" size={20} color={c.text} />
          )}
        </Pressable>
        <View style={styles.divider} />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('map.resetViewLabel')}
          accessibilityHint={t('map.resetViewLabel.hint')}
          onPress={camera.resetView}
          style={({ pressed }) => [styles.btn, pressed && styles.btnPressed]}
          testID="map-reset"
        >
          <Ionicons name="scan-outline" size={20} color={c.text} />
        </Pressable>
        <View style={styles.divider} />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('map.zoomInLabel')}
          onPress={() => camera.zoomBy(0.5)}
          style={({ pressed }) => [styles.btn, pressed && styles.btnPressed]}
          testID="map-zoom-in"
        >
          <Ionicons name="add" size={24} color={c.text} />
        </Pressable>
        <View style={styles.divider} />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('map.zoomOutLabel')}
          onPress={() => camera.zoomBy(2)}
          style={({ pressed }) => [styles.btn, pressed && styles.btnPressed]}
          testID="map-zoom-out"
        >
          <Ionicons name="remove" size={24} color={c.text} />
        </Pressable>
      </View>
    </SafeAreaView>
  );
}

/**
 * Location is permanently denied — the only fix is the OS settings page, so say
 * so once and offer the jump. Dismissible: a map is fully usable without it.
 */
export function LocationBlockedHint({ onDismiss }: { onDismiss: () => void }) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={styles.hint} testID="map-location-blocked">
      <View style={styles.hintBody}>
        <Text style={styles.hintTitle}>{t('map.location.blocked.title')}</Text>
        <Text style={styles.hintText}>{t('map.location.blocked.message')}</Text>
        <Pressable accessibilityRole="button" onPress={() => void openLocationSettings()} hitSlop={8}>
          <Text style={styles.hintCta}>{t('map.location.blocked.cta')}</Text>
        </Pressable>
      </View>
      <Pressable accessibilityRole="button" accessibilityLabel={t('common.close')} onPress={onDismiss} hitSlop={8}>
        <Ionicons name="close" size={18} color={c.muted} />
      </Pressable>
    </View>
  );
}

/** Apple's HIG minimum for a comfortable tap. */
const TAP_TARGET = 44;

/** Slightly wider than tall, so the stack reads as a column of buttons. */
const BUTTON_WIDTH = 46;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    controls: { position: 'absolute', right: 0, bottom: 0, padding: space.md, alignItems: 'flex-end' },
    stack: {
      backgroundColor: c.surface,
      borderRadius: radius.lg,
      overflow: 'hidden',
      shadowColor: '#000',
      shadowOpacity: 0.18,
      shadowRadius: 6,
      shadowOffset: { width: 0, height: 2 },
      elevation: 3,
    },
    // Outside the spacing scale on purpose: these are TAP TARGETS, sized from
    // the 44pt minimum Apple's HIG sets, not from the layout rhythm. Snapping
    // them to a spacing step would make the control either cramped or bloated.
    btn: { width: BUTTON_WIDTH, height: TAP_TARGET, alignItems: 'center', justifyContent: 'center' },
    btnPressed: { backgroundColor: c.primarySoft },
    divider: { height: StyleSheet.hairlineWidth, backgroundColor: c.border },

    hint: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: space.xs,
      backgroundColor: c.surface,
      borderRadius: radius.lg,
      padding: space.sm,
      marginHorizontal: space.md,
      marginTop: space.xs,
    },
    hintBody: { flex: 1, gap: space.xxs },
    hintTitle: { ...type.body, fontWeight: '600', color: c.text },
    hintText: { ...type.bodySm, color: c.muted },
    hintCta: { ...type.bodySm, fontWeight: '600', color: c.primary, paddingTop: space.xxs },
  });
