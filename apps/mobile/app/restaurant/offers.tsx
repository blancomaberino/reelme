import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useArchiveOffer, useMyOffers, useUpdateOffer, useVenues } from '@/api/hooks/useOffers';
import { isPausable, type Offer, offerState } from '@/api/offers';
import { Button } from '@/components/button';
import { OfferCard } from '@/components/offer/offer-card';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { space, type } from '@/theme/tokens';

/**
 * Offer management for a verified restaurant operator (T-042, 06 §2.2).
 *
 * Grouped by venue, and ordered so the ones that need attention come first:
 * everything a diner can currently reach, then drafts, then what has stopped.
 * An operator opens this screen to answer "what is running right now" — a
 * newest-first list buries that under whatever they last touched.
 *
 * Archived offers are hidden entirely. They are terminal (the API never
 * hard-deletes, because redemptions and ledger entries point at them), so they
 * are a permanent, growing tail of rows nobody can act on.
 */
export default function RestaurantOffersScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data: venues, isLoading: venuesLoading } = useVenues();
  const { data: offers, isLoading: offersLoading, isError, refetch } = useMyOffers();
  const update = useUpdateOffer();
  const archive = useArchiveOffer();

  const venueName = useMemo(
    () => new Map((venues ?? []).map((v) => [v.id, v.name])),
    [venues],
  );
  const groups = useMemo(() => groupByVenue(offers ?? []), [offers]);
  const multiVenue = (venues?.length ?? 0) > 1;

  const create = () =>
    router.push({
      pathname: '/restaurant/offer',
      // Pre-select the venue when there is only one — an operator with a single
      // restaurant should never be asked which one.
      params: venues?.length === 1 ? { placeId: venues[0].id } : {},
    });

  const confirmArchive = (offer: Offer) =>
    Alert.alert(t('offers.archive.title'), t('offers.archive.body'), [
      { text: t('common.cancel'), style: 'cancel' },
      {
        text: t('offers.archive.confirm'),
        style: 'destructive',
        onPress: () => archive.mutate(offer.id),
      },
    ]);

  const loading = venuesLoading || offersLoading;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader
        title={t('offers.title')}
        divided
        right={
          (venues?.length ?? 0) > 0 ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('offers.new')}
              onPress={create}
              hitSlop={12}
              testID="offers-new"
            >
              <Ionicons name="add" size={26} color={c.primary} />
            </Pressable>
          ) : undefined
        }
      />

      {loading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} accessibilityLabel={t('common.loading')} />
      ) : isError ? (
        <View style={styles.empty}>
          <Ionicons name="cloud-offline-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('common.error.general')}</Text>
          <Button title={t('common.tryAgain')} variant="secondary" onPress={() => void refetch()} />
        </View>
      ) : (venues?.length ?? 0) === 0 ? (
        /* No verified claim — the restaurant surface is not theirs yet. This is
           reachable by deep link even though Profile hides the entry point. */
        <View style={styles.empty}>
          <Ionicons name="storefront-outline" size={40} color={c.muted} />
          <Text style={styles.emptyTitle}>{t('offers.noVenue.title')}</Text>
          <Text style={styles.emptyText}>{t('offers.noVenue.body')}</Text>
        </View>
      ) : groups.length === 0 ? (
        <View style={styles.empty}>
          <Ionicons name="pricetag-outline" size={40} color={c.muted} />
          <Text style={styles.emptyTitle}>{t('offers.empty.title')}</Text>
          <Text style={styles.emptyText}>{t('offers.empty.body')}</Text>
          <Button title={t('offers.empty.cta')} icon="add" onPress={create} />
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          {groups.map(([placeId, group]) => (
            <View key={placeId} style={styles.group}>
              {multiVenue ? (
                <View style={styles.groupHead}>
                  <Text style={styles.groupTitle} numberOfLines={1}>
                    {venueName.get(placeId) ?? t('offers.unknownVenue')}
                  </Text>
                  <View style={styles.rule} />
                </View>
              ) : null}

              {group.map((offer) => {
                const state = offerState(offer);
                const busy =
                  (update.isPending && update.variables?.id === offer.id) ||
                  (archive.isPending && archive.variables === offer.id);

                return (
                  <OfferCard
                    key={offer.id}
                    offer={offer}
                    onPress={() => router.push({ pathname: '/restaurant/offer', params: { id: offer.id } })}
                    actions={
                      <>
                        {isPausable(state) ? (
                          <Button
                            title={t('offers.action.pause')}
                            variant="ghost"
                            size="sm"
                            icon="pause"
                            loading={busy}
                            onPress={() => update.mutate({ id: offer.id, status: 'paused' })}
                          />
                        ) : state === 'paused' || state === 'draft' ? (
                          <Button
                            title={t(state === 'draft' ? 'offers.action.publish' : 'offers.action.resume')}
                            variant="ghost"
                            size="sm"
                            icon="play"
                            loading={busy}
                            onPress={() => update.mutate({ id: offer.id, status: 'active' })}
                          />
                        ) : null}

                        <Button
                          title={t('offers.action.edit')}
                          variant="ghost"
                          size="sm"
                          icon="create-outline"
                          onPress={() => router.push({ pathname: '/restaurant/offer', params: { id: offer.id } })}
                        />

                        <View style={styles.spacer} />

                        <Button
                          title={t('offers.action.archive')}
                          variant="link"
                          size="sm"
                          onPress={() => confirmArchive(offer)}
                        />
                      </>
                    }
                  />
                );
              })}
            </View>
          ))}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

/**
 * Diner-reachable first, then what still needs a decision, then what has
 * stopped. Within a rank, newest first.
 */
const RANK = { live: 0, soldOut: 1, scheduled: 2, draft: 3, paused: 4, ended: 5, archived: 6 } as const;

/**
 * Group the flat `?mine=1` list by venue, dropping archived offers.
 *
 * Exported for the unit test: the ordering rule is the screen's one real piece
 * of logic, and testing it through a rendered list would only prove that three
 * cards appeared in some order.
 */
export function groupByVenue(offers: Offer[], now: Date = new Date()): [string, Offer[]][] {
  const groups = new Map<string, Offer[]>();

  for (const offer of offers) {
    if (offer.status === 'archived') continue;
    const bucket = groups.get(offer.place_id);
    if (bucket) bucket.push(offer);
    else groups.set(offer.place_id, [offer]);
  }

  for (const bucket of groups.values()) {
    bucket.sort((a, b) => {
      const rank = RANK[offerState(a, now)] - RANK[offerState(b, now)];
      return rank !== 0 ? rank : Number(b.id) - Number(a.id);
    });
  }

  return [...groups.entries()];
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { paddingVertical: space.xxl },
    scroll: { padding: space.md, gap: space.lg, paddingBottom: space.xxl },
    group: { gap: space.sm },
    groupHead: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
    groupTitle: { ...type.bodyLg, fontFamily: fonts.display, color: c.ink2 },
    /** Hairline that runs out from the venue name — a market-board divider. */
    rule: { flex: 1, height: StyleSheet.hairlineWidth, backgroundColor: c.line2 },
    spacer: { flex: 1 },
    empty: { alignItems: 'center', gap: space.sm, paddingTop: space.xxl, paddingHorizontal: space.xl },
    emptyTitle: { ...type.title, color: c.text, textAlign: 'center' },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },
  });
