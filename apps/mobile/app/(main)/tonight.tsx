import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useTagCatalog } from '@/api/hooks/useTags';
import { DEFAULT_ZONE_M, useTonight, type ZoneM, ZONES_M } from '@/api/hooks/useTonight';
import { queryKeys } from '@/api/keys';
import type { PlaceSummary } from '@/api/places';
import { Button } from '@/components/button';
import { LocationBlockedHint } from '@/components/map/map-controls';
import { Chip } from '@/components/place/chip';
import { MyPlaceCard } from '@/components/place/my-place-card';
import { useT } from '@/i18n';
import { useDebounced } from '@/lib/use-debounced';
import { useFormat } from '@/lib/use-format';
import { locateUser } from '@/lib/initial-region';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/** How many dish suggestions fit above the fold without becoming a wall. */
const DISH_SUGGESTIONS = 8;

/**
 * Tonight (T-158) — the surface that answers "where do I eat, here, now".
 *
 * The screen is one question with three dials: how far I will go, what I feel
 * like eating, and whether it has to be open right now. So the layout says the
 * ANSWER first — "8 places open within 2 km", in the serif, directly under the
 * title — and puts the dials beneath it. That line is the only bold thing here;
 * it changes as you tap, which is what makes the controls legible without a
 * label above each one.
 *
 * Sibling-first throughout: the rows are {@link MyPlaceCard} (the list twin of a
 * map pin, minus the swipe-to-remove that only makes sense on my own places),
 * the pills are {@link Chip}, the position is {@link locateUser} and its refusal
 * is {@link LocationBlockedHint} — the same one the map uses. Nothing here is a
 * second implementation of something the app already has.
 *
 * Location is REQUIRED rather than a nice-to-have: "near you" without a fix is
 * either an empty screen or a list from a city you are not in, and the second is
 * worse than the first.
 */
export default function TonightScreen() {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);

  const [zone, setZone] = useState<ZoneM>(DEFAULT_ZONE_M);
  const [openNow, setOpenNow] = useState(true);
  const [dish, setDish] = useState('');
  const [hintDismissed, setHintDismissed] = useState(false);
  // Debounced so typing "milanesa" is one request at the end, not eight.
  const debouncedDish = useDebounced(dish, 300);

  /*
   * The device fix is a QUERY, not an effect writing state: `locateUser` answers
   * with a reason instead of throwing, so a refusal is data and the retry button
   * is `refetch()` rather than a second copy of the same logic. Shared key with
   * the offers browse, so arriving here after that screen costs no second prompt.
   */
  const fix = useQuery({
    queryKey: queryKeys.deviceLocation(),
    queryFn: locateUser,
    staleTime: 5 * 60_000,
    retry: false,
  });

  const at = fix.data?.ok ? fix.data.region : null;
  const blocked = fix.data && !fix.data.ok ? fix.data.reason : null;

  const list = useTonight({ at, radiusM: zone, dish: debouncedDish, openNow });
  const places = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);

  // Dish suggestions come from the tag catalog the app already loads, filtered
  // to the dish vocabulary — so the chips are the shared, translatable facet
  // names, while the text field stays free over the verbatim menu corpus.
  const catalog = useTagCatalog();
  const suggestions = useMemo(
    () => (catalog.data ?? []).filter((tag) => tag.kind === 'dish').slice(0, DISH_SUGGESTIONS),
    [catalog.data],
  );

  const answer = at
    ? t(openNow ? 'tonight.answer.open' : 'tonight.answer.any', {
        count: places.length,
        km: zone / 1000,
      })
    : t('tonight.answer.locating');

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.title}>{t('tonight.title')}</Text>
        <Text style={styles.answer} testID="tonight-answer">
          {list.isPending && at ? t('tonight.answer.looking') : answer}
        </Text>
      </View>

      <View style={styles.controls}>
        <View style={styles.pillRow}>
          <Chip
            label={t('tonight.openNow')}
            selected={openNow}
            onPress={() => setOpenNow((on) => !on)}
          />
          {ZONES_M.map((m) => (
            <Chip key={m} label={t('tonight.zone', { km: m / 1000 })} selected={zone === m} onPress={() => setZone(m)} />
          ))}
        </View>

        <View style={styles.inputWrap}>
          <Ionicons name="restaurant-outline" size={18} color={c.muted} />
          <TextInput
            style={styles.input}
            placeholder={t('tonight.dish.placeholder')}
            placeholderTextColor={c.placeholder}
            value={dish}
            onChangeText={setDish}
            autoCorrect={false}
            autoCapitalize="none"
            returnKeyType="search"
            accessibilityLabel={t('tonight.dish.label')}
            testID="tonight-dish"
          />
          {dish.length > 0 ? (
            <Pressable accessibilityLabel={t('search.clear')} onPress={() => setDish('')} hitSlop={8}>
              <Ionicons name="close-circle" size={18} color={c.placeholder} />
            </Pressable>
          ) : null}
        </View>

        {suggestions.length > 0 && dish.length === 0 ? (
          <View style={styles.suggestions}>
            {suggestions.map((tag) => (
              <Chip key={tag.id} label={tag.label ?? fmt.tag(tag.name)} onPress={() => setDish(tag.name)} />
            ))}
          </View>
        ) : null}
      </View>

      {blocked && !hintDismissed ? <LocationBlockedHint onDismiss={() => setHintDismissed(true)} /> : null}

      <TonightBody
        blocked={blocked !== null}
        list={list}
        places={places}
        onRetryLocation={() => void fix.refetch()}
        styles={styles}
        c={c}
      />
    </SafeAreaView>
  );
}

/**
 * The four states this list can be in, kept apart on purpose: an EMPTY result
 * and a FAILED request are different facts and must not share a screen. "Nothing
 * is open within 1 km" invites you to widen the zone; "we couldn't reach the
 * server" invites you to retry. Collapsing them into one grey message is how a
 * person ends up widening a search that never ran.
 */
function TonightBody({
  blocked,
  list,
  places,
  onRetryLocation,
  styles,
  c,
}: {
  blocked: boolean;
  list: ReturnType<typeof useTonight>;
  places: PlaceSummary[];
  onRetryLocation: () => void;
  styles: Styles;
  c: Palette;
}) {
  const t = useT();

  if (blocked) {
    return (
      <View style={styles.state}>
        <Text style={styles.stateText}>{t('tonight.needsLocation')}</Text>
        <Button title={t('common.tryAgain')} variant="secondary" onPress={onRetryLocation} />
      </View>
    );
  }

  if (list.isError) {
    return (
      <View style={styles.state} testID="tonight-error">
        <Text style={styles.stateText}>{t('common.error.general')}</Text>
        <Button title={t('common.tryAgain')} variant="secondary" onPress={() => void list.refetch()} />
      </View>
    );
  }

  if (list.isPending) {
    return <ActivityIndicator style={styles.loading} color={c.primary} accessibilityLabel={t('common.loading')} />;
  }

  if (places.length === 0) {
    return (
      <View style={styles.state} testID="tonight-empty">
        <Text style={styles.stateText}>{t('tonight.empty')}</Text>
      </View>
    );
  }

  return (
    <FlashList
      data={places}
      keyExtractor={(place) => place.id}
      contentContainerStyle={styles.list}
      renderItem={({ item }) => (
        <MyPlaceCard
          place={item}
          onPress={(slug) => router.push({ pathname: '/place/[slug]', params: { slug } })}
        />
      )}
      onEndReachedThreshold={0.5}
      onEndReached={() => {
        if (list.hasNextPage && !list.isFetchingNextPage) void list.fetchNextPage();
      }}
      ListFooterComponent={
        list.isFetchingNextPage ? <ActivityIndicator style={styles.loading} color={c.primary} /> : null
      }
    />
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { paddingHorizontal: space.md, paddingTop: space.xs },
    title: { ...type.display, color: c.text },
    // The answer, not a subtitle: it states what the dials below currently ask
    // for, and it is the thing that visibly changes when one of them is tapped.
    answer: { ...type.body, color: c.muted, marginTop: space.xxs },
    controls: { paddingHorizontal: space.md, paddingTop: space.sm, gap: space.xs },
    pillRow: { flexDirection: 'row', gap: space.xs, flexWrap: 'wrap' },
    inputWrap: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.xs,
      backgroundColor: c.surface,
      borderColor: c.border,
      borderWidth: StyleSheet.hairlineWidth,
      borderRadius: radius.sm,
      paddingHorizontal: space.sm,
      paddingVertical: space.xs,
    },
    input: { flex: 1, ...type.body, color: c.text, padding: space.none },
    suggestions: { flexDirection: 'row', gap: space.xs, flexWrap: 'wrap' },
    list: { paddingHorizontal: space.md, paddingTop: space.sm, paddingBottom: space.xl },
    state: { alignItems: 'center', gap: space.sm, paddingHorizontal: space.xl, paddingTop: space.xxl },
    stateText: { ...type.body, color: c.muted, textAlign: 'center' },
    loading: { marginTop: space.lg },
  });
