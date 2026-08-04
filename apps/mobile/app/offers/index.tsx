import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { useQuery } from '@tanstack/react-query';
import { Stack, router } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import MapView, { PROVIDER_DEFAULT } from 'react-native-maps';

import { BROWSE_RADIUS_M, useNearbyOffers } from '@/api/hooks/useNearbyOffers';
import { queryKeys } from '@/api/keys';
import type { Offer } from '@/api/offers';
import { Button } from '@/components/button';
import { LocationBlockedHint, MapControls, useMapCamera, useMapSelection } from '@/components/map/map-controls';
import { OfferMarker } from '@/components/map/offer-marker';
import { discountHeadline, OfferCard } from '@/components/offer/offer-card';
import { ScreenHeader } from '@/components/screen-header';
import { type MessageKey, useT } from '@/i18n';
import { type Region, regionRadiusM } from '@/lib/geo';
import { DEFAULT_REGION, locateUser } from '@/lib/initial-region';
import { openLocationSettings, USER_REGION_DELTA } from '@/lib/location';
import { useSettingsStore } from '@/stores/settings';
import { useViewportStore } from '@/stores/viewport';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

type Mode = 'list' | 'map';

/** The `t()` from {@link useT} — passed to the pure label helper below. */
type Translate = (key: MessageKey, params?: Record<string, string | number>) => string;

/**
 * Nearby offers (T-047, 05 screen #17).
 *
 * Location is REQUIRED, not a nice-to-have — "offers near you" with no fix is
 * either an empty screen or a list from somewhere the diner isn't, and the
 * second is worse than the first. So a missing permission gets its own state
 * with the one action that fixes it, rather than a spinner that never resolves.
 *
 * The same `OfferCard` renders here and in the operator's management list. A
 * diner and an operator looking at the same promotion see the same card, which
 * is the only way "what I published" and "what they see" stay honest.
 */
export default function OffersBrowseScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const currency = useSettingsStore((s) => s.currency);

  const [mode, setMode] = useState<Mode>('list');
  // Not a plain useState: the background handler below has to ignore the map
  // press that some builds fire alongside a marker press, or every pin tap
  // selects and instantly deselects — which looks like a dead pin.
  const { selected, selectFromMarker, onBackgroundPress } = useMapSelection<string>();

  /*
   * The device fix is a QUERY, not an effect writing state. `locateUser` never
   * throws — it answers with a reason — so the refusal is data, and the retry
   * button is `refetch()` rather than a second copy of the same logic. It also
   * means a fix is not re-requested every time this screen is revisited.
   */
  const fix = useQuery({
    queryKey: queryKeys.deviceLocation(),
    queryFn: locateUser,
    staleTime: 5 * 60_000,
    retry: false,
  });

  const at = fix.data?.ok ? fix.data.region : null;
  const blocked = fix.data && !fix.data.ok ? fix.data.reason : null;

  /*
   * Where the MAP opens, which is a different question from where the LIST
   * queries. The list needs a real fix — "offers near you" from a city you are
   * not in is a lie — but the map must always draw something, so it falls back
   * the same way the home map does: the last viewport you settled on, then the
   * seed city. Rendering nothing was why this looked broken next to the real map.
   */
  const savedRegion = useViewportStore((s) => s.saved);
  const mapRegion = at
    ? { ...at, latitudeDelta: USER_REGION_DELTA, longitudeDelta: USER_REGION_DELTA }
    : (savedRegion ?? DEFAULT_REGION);

  const camera = useMapCamera({ initialRegion: mapRegion, initialUserRegion: at });

  /*
   * The map searches WHAT IS ON SCREEN; the list searches around you.
   *
   * They are different questions. "Offers near me" is a list of places I could
   * walk to. A map I have dragged across town is a question about THERE — and
   * a map pinned to my own position answers it with an empty screen no matter
   * how far I pan, which is exactly what it did before this.
   *
   * Updated only when the region SETTLES (debounced), never per gesture frame.
   */
  /*
   * DERIVED, not snapshotted. `at` is null on the first render (the fix is a
   * query), so seeding state with `mapRegion` froze the search on the fallback
   * city: the fix would land, the list would follow it, and map mode would go
   * on asking about the seed region until the user panned. Falling back to
   * `mapRegion` until an actual pan happens means the fix is picked up for
   * free, and the pan then wins for good.
   */
  const [pannedTo, setPannedTo] = useState<Region | null>(null);
  const mapArea = pannedTo ?? mapRegion;
  const settle = useRef<ReturnType<typeof setTimeout> | null>(null);

  const onRegionSettled = useCallback(
    (region: Region) => {
      camera.rememberRegion(region);
      if (settle.current) clearTimeout(settle.current);
      settle.current = setTimeout(() => setPannedTo(region), 400);
    },
    [camera],
  );

  useEffect(() => () => {
    if (settle.current) clearTimeout(settle.current);
  }, []);

  const searchAt = mode === 'map' ? mapArea : at;
  const searchRadius = mode === 'map' ? regionRadiusM(mapArea) : BROWSE_RADIUS_M;

  const { data: offers, isLoading, isError, refetch, isRefetching } = useNearbyOffers(searchAt, searchRadius);

  const mappable = useMemo(
    () => (offers ?? []).filter((o) => o.place?.lat != null && o.place?.lng != null),
    [offers],
  );
  const selectedOffer = useMemo(() => mappable.find((o) => o.id === selected) ?? null, [mappable, selected]);

  const open = useCallback((offer: Offer) => {
    if (offer.place?.slug) router.push({ pathname: '/place/[slug]', params: { slug: offer.place.slug } });
  }, []);

  const redeem = useCallback((offer: Offer) => {
    router.push({ pathname: '/offers/[id]/redeem', params: { id: offer.id } });
  }, []);

  /**
   * The map, which never depends on having a fix.
   *
   * It opens on the user's position when we have one and on the same fallback
   * chain as the home map otherwise (last settled viewport → seed city), so it
   * always draws something. "Locate me" in the control stack is a better answer
   * to a missing fix than an empty screen with a retry button.
   */
  const mapBody = () => (
    <View style={styles.mapWrap}>
      <MapView
        ref={camera.mapRef}
        provider={PROVIDER_DEFAULT}
        style={StyleSheet.absoluteFill}
        initialRegion={mapRegion}
        /*
         * Remounted once, when the first fix arrives, because an uncontrolled
         * MapView reads `initialRegion` exactly once. Without this a map opened
         * before the fix resolved stays on the seed city. `at` only goes
         * null→set, so this can happen at most once, and never after a pan
         * (the user's own region is what `initialRegion` then holds).
         */
        key={at ? 'located' : 'fallback'}
        onRegionChangeComplete={onRegionSettled}
        onPress={onBackgroundPress}
        showsUserLocation
        showsMyLocationButton={false}
        // Same as the home map: Apple's own POI pins clutter the view and are
        // easy to tap instead of ours.
        showsPointsOfInterests={false}
        testID="offers-map"
      >
        {/* The discount, not a generic pin — on a map of ten venues the number
            IS the reason to walk to one of them. */}
        {mappable.map((offer) => (
          <OfferMarker
            key={offer.id}
            id={offer.id}
            lat={offer.place!.lat!}
            lng={offer.place!.lng!}
            label={shortHeadline(offer, currency, t)}
            selected={selected === offer.id}
            onPress={selectFromMarker}
            accessibilityLabel={`${shortHeadline(offer, currency, t)} — ${offer.place?.name ?? offer.title}`}
          />
        ))}
      </MapView>

      {camera.locateBlocked ? (
        <SafeAreaView edges={['top']} pointerEvents="box-none">
          <LocationBlockedHint onDismiss={() => camera.setLocateBlocked(false)} />
        </SafeAreaView>
      ) : null}

      {/* A venue with no coordinates cannot be a marker, and silently dropping
          it makes the map disagree with the list. */}
      {mappable.length < (offers?.length ?? 0) ? (
        <Text style={styles.mapNote}>
          {t('offers.browse.notMapped', { count: (offers?.length ?? 0) - mappable.length })}
        </Text>
      ) : null}

      <MapControls camera={camera} />

      {selectedOffer ? (
        <View style={styles.sheet} testID="offers-map-sheet">
          <OfferCard
            offer={selectedOffer}
            venueName={selectedOffer.place?.name}
            onPress={() => open(selectedOffer)}
            actions={<Button title={t('offers.browse.getCode')} size="sm" onPress={() => redeem(selectedOffer)} />}
          />
        </View>
      ) : null}
    </View>
  );

  const body = () => {
    // The map is exempt from the location gates below — it has its own answer
    // to a missing fix, and it is the one view that can still be useful without
    // one.
    if (mode === 'map') return mapBody();

    if (blocked !== null) {
      return (
        <View style={styles.centered} testID="offers-location-blocked">
          <Ionicons name="location-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>
            {t(blocked === 'unavailable' ? 'offers.browse.noFix' : 'offers.browse.needLocation')}
          </Text>
          <Button
            title={t(blocked === 'blocked' ? 'offers.browse.openSettings' : 'common.tryAgain')}
            variant="secondary"
            onPress={() => {
              if (blocked === 'blocked') void openLocationSettings();
              else void fix.refetch();
            }}
            testID="offers-location-cta"
          />
        </View>
      );
    }

    // `isPending` only — NOT `isFetching`. A background refetch after the
    // 5-minute staleTime would otherwise replace a rendered list with a spinner
    // while re-reading a fix we already have.
    if (fix.isPending || isLoading) {
      return <ActivityIndicator color={c.primary} style={styles.loading} accessibilityLabel={t('common.loading')} />;
    }

    if (isError) {
      return (
        <View style={styles.centered}>
          <Ionicons name="cloud-offline-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('common.error.general')}</Text>
          <Button title={t('common.tryAgain')} variant="secondary" onPress={() => void refetch()} />
        </View>
      );
    }

    return (
      <FlashList
        data={offers ?? []}
        keyExtractor={(offer) => offer.id}
        contentContainerStyle={styles.list}
        refreshControl={
          <RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={c.primary} />
        }
        renderItem={({ item }) => (
          <View style={styles.row}>
            <OfferCard
              offer={item}
              venueName={item.place?.name}
              onPress={() => open(item)}
              actions={<Button title={t('offers.browse.getCode')} size="sm" onPress={() => redeem(item)} />}
            />
          </View>
        )}
        ListEmptyComponent={
          <View style={styles.centered} testID="offers-empty">
            <Ionicons name="pricetags-outline" size={40} color={c.muted} />
            <Text style={styles.emptyText}>{t('offers.browse.empty', { km: BROWSE_RADIUS_M / 1000 })}</Text>
          </View>
        }
      />
    );
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('offers.browse.title')} />

      <View style={styles.toggle}>
        {(['list', 'map'] as const).map((option) => (
          <Pressable
            key={option}
            accessibilityRole="tab"
            accessibilityState={{ selected: mode === option }}
            onPress={() => setMode(option)}
            style={[styles.toggleTab, mode === option && styles.toggleTabActive]}
            testID={`offers-toggle-${option}`}
          >
            <Ionicons
              name={option === 'list' ? 'list-outline' : 'map-outline'}
              size={16}
              color={mode === option ? c.text : c.muted}
            />
            <Text style={[styles.toggleLabel, mode === option && styles.toggleLabelActive]}>
              {t(option === 'list' ? 'offers.browse.list' : 'offers.browse.map')}
            </Text>
          </Pressable>
        ))}
      </View>

      {body()}
    </SafeAreaView>
  );
}

/**
 * The map pin's text.
 *
 * Reuses the CARD's formatter for everything it can, so a pin and the card it
 * opens can never state different discounts. Only `free_item` is abbreviated —
 * "2 free items" does not fit a marker, while "×2" beside a restaurant does.
 *
 * Rounding was the trap here: a pin is short, but "€3.50 off" abbreviated to
 * "€4" promises more than the offer gives, and the diner finds out at the till.
 * Shorter is allowed; wrong is not.
 */
function shortHeadline(offer: Offer, currencySymbol: string, t: Translate): string {
  return offer.discount_type === 'free_item'
    ? `×${offer.discount_value}`
    : discountHeadline(offer, currencySymbol, t);
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { paddingVertical: space.xxl },
    list: { paddingBottom: space.xxl },
    row: { paddingHorizontal: space.md, paddingTop: space.sm },
    centered: { alignItems: 'center', gap: space.sm, paddingTop: space.xl, paddingHorizontal: space.xl },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },

    toggle: {
      flexDirection: 'row',
      gap: space.xxs,
      margin: space.md,
      padding: space.xxs,
      backgroundColor: c.surface2,
      borderRadius: radius.pill,
    },
    toggleTab: {
      flex: 1,
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: space.xxs,
      paddingVertical: space.xs,
      borderRadius: radius.pill,
    },
    toggleTabActive: { backgroundColor: c.surface },
    toggleLabel: { ...type.bodySm, color: c.muted },
    toggleLabelActive: { color: c.text, fontWeight: '600' },

    mapWrap: { flex: 1 },
    map: { flex: 1 },
    sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, padding: space.md },
    mapNote: {
      ...type.caption,
      color: c.muted,
      textAlign: 'center',
      paddingVertical: space.xs,
      backgroundColor: c.surface,
    },
  });
