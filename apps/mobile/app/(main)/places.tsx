import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { router } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useMyPlaces } from '@/api/hooks/useMyPlaces';
import { useRemoveFromMap } from '@/api/hooks/useRemoveFromMap';
import type { MyPlacesFilters as Filters } from '@/api/keys';
import type { PlaceSummary } from '@/api/places';
import { MyPlaceCard } from '@/components/place/my-place-card';
import { MyPlacesFilters } from '@/components/place/my-places-filters';
import { type MessageKey, useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

type T = (key: MessageKey) => string;

/**
 * "Mis lugares" (T-071) — the list view of my map, replacing the removed global
 * feed. Places I shared (not soft-hidden) ∪ places I saved, narrowed by the
 * country / type / tag facets. Same personal dataset as the home map.
 */
export default function MyPlacesScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const authed = useSessionStore((s) => s.status === 'authed');

  const [filters, setFilters] = useState<Filters>({ sort: 'recent' });
  const onChange = useCallback((patch: Partial<Filters>) => setFilters((f) => ({ ...f, ...patch })), []);

  const { data, isLoading, isError, refetch, isRefetching, fetchNextPage, hasNextPage, isFetchingNextPage } =
    useMyPlaces(filters, { enabled: authed });
  const remove = useRemoveFromMap();

  const items = useMemo(() => data?.pages.flatMap((p) => p.data) ?? [], [data]);

  // Facet chips derive from the UNFILTERED collection (only sort applied), so
  // picking a country/type can't collapse the chip list to that one value and
  // strand you from switching directly to another (BUG G).
  const facetSource = useMyPlaces({ sort: filters.sort }, { enabled: authed });
  const facetItems = useMemo(
    () => facetSource.data?.pages.flatMap((p) => p.data) ?? [],
    [facetSource.data],
  );

  const onPressCard = useCallback((slug: string) => {
    router.push({ pathname: '/place/[slug]', params: { slug } });
  }, []);

  const onRemove = useCallback(
    (place: PlaceSummary) => {
      // On the aggregate "my places", removing offers a choice (T-073): hide the
      // pin (stays in your lists) or fully remove it (deletes your share).
      Alert.alert(t('myPlaces.removeConfirm.title'), t('myPlaces.removeConfirm.message', { name: place.name }), [
        { text: t('common.cancel'), style: 'cancel' },
        { text: t('myPlaces.remove.hide'), onPress: () => remove.mutate({ place, mode: 'hide' }) },
        { text: t('myPlaces.remove.full'), style: 'destructive', onPress: () => remove.mutate({ place, mode: 'full' }) },
      ]);
    },
    [remove, t],
  );

  const renderItem = useCallback(
    ({ item }: { item: PlaceSummary }) => <MyPlaceCard place={item} onPress={onPressCard} onRemove={onRemove} />,
    [onPressCard, onRemove],
  );

  const onEndReached = useCallback(() => {
    if (hasNextPage && !isFetchingNextPage) void fetchNextPage();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.title}>{t('myPlaces.title')}</Text>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('myPlaces.search')}
          onPress={() => router.push('/search')}
          hitSlop={10}
        >
          <Ionicons name="search" size={22} color={c.text} />
        </Pressable>
      </View>

      {!authed ? (
        <SignInPrompt styles={styles} c={c} t={t} />
      ) : isError ? (
        <ErrorState styles={styles} c={c} t={t} onRetry={() => void refetch()} />
      ) : (
        // The filter bar stays mounted while a facet change refetches (only the
        // list area shows the spinner), so an open filter sheet isn't torn down
        // mid-selection.
        <>
          <MyPlacesFilters places={facetItems.length ? facetItems : items} filters={filters} onChange={onChange} />
          {isLoading ? (
            <View style={styles.center}>
              <ActivityIndicator color={c.primary} />
            </View>
          ) : (
            <FlashList
              data={items}
              renderItem={renderItem}
              keyExtractor={(item) => item.id}
              style={styles.flashList}
              contentContainerStyle={styles.list}
              ItemSeparatorComponent={Separator}
              onEndReached={onEndReached}
              onEndReachedThreshold={0.5}
              refreshControl={
                <RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={c.primary} />
              }
              ListEmptyComponent={<EmptyState styles={styles} c={c} t={t} filtered={hasActiveFacets(filters)} />}
              ListFooterComponent={
                isFetchingNextPage ? <ActivityIndicator style={styles.footer} color={c.primary} /> : null
              }
            />
          )}
        </>
      )}
    </SafeAreaView>
  );
}

function hasActiveFacets(f: Filters): boolean {
  return !!f.country || !!f.type || (f.tags?.length ?? 0) > 0;
}

function Separator() {
  return <View style={styles12} />;
}
const styles12 = { height: 12 } as const;

function EmptyState({ styles, c, t, filtered }: { styles: Styles; c: Palette; t: T; filtered: boolean }) {
  return (
    <View style={styles.empty}>
      <Ionicons name={filtered ? 'filter-outline' : 'bookmark-outline'} size={40} color={c.muted} />
      <Text style={styles.emptyTitle}>{t(filtered ? 'myPlaces.empty.filteredTitle' : 'myPlaces.empty.title')}</Text>
      <Text style={styles.emptyBody}>{t(filtered ? 'myPlaces.empty.filteredBody' : 'myPlaces.empty.body')}</Text>
    </View>
  );
}

function SignInPrompt({ styles, c, t }: { styles: Styles; c: Palette; t: T }) {
  return (
    <View style={styles.empty}>
      <Ionicons name="bookmark-outline" size={40} color={c.muted} />
      <Text style={styles.emptyTitle}>{t('myPlaces.guest.title')}</Text>
      <Text style={styles.emptyBody}>{t('myPlaces.guest.body')}</Text>
      <Pressable accessibilityRole="button" onPress={() => router.push('/(auth)/login')} style={styles.retry}>
        <Text style={styles.retryText}>{t('myPlaces.guest.cta')}</Text>
      </Pressable>
    </View>
  );
}

function ErrorState({ styles, c, t, onRetry }: { styles: Styles; c: Palette; t: T; onRetry: () => void }) {
  return (
    <View style={styles.center}>
      <Ionicons name="cloud-offline-outline" size={40} color={c.muted} />
      <Text style={styles.emptyTitle}>{t('myPlaces.error.title')}</Text>
      <Pressable accessibilityRole="button" onPress={onRetry} style={styles.retry}>
        <Text style={styles.retryText}>{t('common.tryAgain')}</Text>
      </Pressable>
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      paddingHorizontal: 20,
      paddingVertical: 12,
    },
    title: { fontSize: 28, fontWeight: '800', letterSpacing: -0.5, color: c.text },
    flashList: { flex: 1 },
    list: { paddingHorizontal: 16, paddingBottom: 24 },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 10, padding: 32 },
    footer: { paddingVertical: 20 },
    empty: { alignItems: 'center', justifyContent: 'center', gap: 8, paddingTop: 80, paddingHorizontal: 32 },
    emptyTitle: { fontSize: 18, fontWeight: '700', color: c.text, textAlign: 'center' },
    emptyBody: { fontSize: 15, color: c.muted, textAlign: 'center' },
    retry: {
      marginTop: 12,
      paddingHorizontal: 20,
      paddingVertical: 10,
      borderRadius: 12,
      borderWidth: 1.5,
      borderColor: c.primary,
    },
    retryText: { color: c.primary, fontWeight: '600', fontSize: 15 },
  });
