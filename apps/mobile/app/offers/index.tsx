import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { useQuery } from '@tanstack/react-query';
import { Stack, router } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import MapView, { Marker, PROVIDER_DEFAULT } from 'react-native-maps';

import { BROWSE_RADIUS_M, useNearbyOffers } from '@/api/hooks/useNearbyOffers';
import { queryKeys } from '@/api/keys';
import type { Offer } from '@/api/offers';
import { Button } from '@/components/button';
import { OfferCard } from '@/components/offer/offer-card';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { locateUser } from '@/lib/initial-region';
import { openLocationSettings, USER_REGION_DELTA } from '@/lib/location';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

type Mode = 'list' | 'map';

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

  const [mode, setMode] = useState<Mode>('list');
  const [selected, setSelected] = useState<string | null>(null);

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

  const { data: offers, isLoading, isError, refetch, isRefetching } = useNearbyOffers(at);

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

  const body = () => {
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

    if (fix.isPending || fix.isFetching || isLoading) {
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

    if (mode === 'map') {
      return (
        <View style={styles.mapWrap}>
          {at ? (
            <MapView
              style={styles.map}
              provider={PROVIDER_DEFAULT}
              initialRegion={{ ...at, latitudeDelta: USER_REGION_DELTA, longitudeDelta: USER_REGION_DELTA }}
              showsUserLocation
              onPress={() => setSelected(null)}
              testID="offers-map"
            >
              {mappable.map((offer) => (
                <Marker
                  key={offer.id}
                  coordinate={{ latitude: offer.place!.lat!, longitude: offer.place!.lng! }}
                  onPress={() => setSelected(offer.id)}
                  tracksViewChanges={false}
                >
                  {/* The discount, not a generic pin — on a map of ten venues
                      the number IS the reason to walk to one of them. */}
                  <View style={[styles.pin, selected === offer.id && styles.pinSelected]}>
                    <Text style={styles.pinText}>{shortHeadline(offer)}</Text>
                  </View>
                </Marker>
              ))}
            </MapView>
          ) : null}

          {selectedOffer ? (
            <View style={styles.sheet} testID="offers-map-sheet">
              <OfferCard
                offer={selectedOffer}
                venueName={selectedOffer.place?.name}
                onPress={() => open(selectedOffer)}
                actions={
                  <Button title={t('offers.browse.getCode')} size="sm" onPress={() => redeem(selectedOffer)} />
                }
              />
            </View>
          ) : null}

          {/* A venue with no coordinates cannot be a marker, and silently
              dropping it makes the map disagree with the list. */}
          {mappable.length < (offers?.length ?? 0) ? (
            <Text style={styles.mapNote}>
              {t('offers.browse.notMapped', { count: (offers?.length ?? 0) - mappable.length })}
            </Text>
          ) : null}
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
 * The map pin's text. A marker is read at a glance across a whole city block of
 * pins, so it carries the number alone — the units and the title are in the
 * card that opens when it is tapped.
 */
function shortHeadline(offer: Offer): string {
  if (offer.discount_type === 'percent') return `${offer.discount_value}%`;
  if (offer.discount_type === 'free_item') return `×${offer.discount_value}`;

  return `${Math.round(offer.discount_value / 100)}`;
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
    pin: {
      backgroundColor: c.primary,
      borderRadius: radius.pill,
      paddingHorizontal: space.sm,
      paddingVertical: space.xxs,
      borderWidth: 2,
      borderColor: c.surface,
    },
    pinSelected: { backgroundColor: c.text },
    pinText: { ...type.bodySm, fontFamily: fonts.display, color: c.onPrimary },
    sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, padding: space.md },
    mapNote: {
      ...type.caption,
      color: c.muted,
      textAlign: 'center',
      paddingVertical: space.xs,
      backgroundColor: c.surface,
    },
  });
