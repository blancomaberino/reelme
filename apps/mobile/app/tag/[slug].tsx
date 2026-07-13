import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useCallback, useMemo } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { usePlacesByTag } from '@/api/hooks/usePlacesByTag';
import type { PlaceSummary } from '@/api/places';
import { cuisinePriceLine } from '@/lib/format';
import { fonts, type Palette, useColors } from '@/theme/colors';

/** Places carrying a tag (T-034): reached from a search Tags result. */
export default function TagResultsScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data, isLoading, isError, fetchNextPage, hasNextPage, isFetchingNextPage } = usePlacesByTag(slug ?? '');

  const items = useMemo(() => data?.pages.flatMap((p) => p.data) ?? [], [data]);

  const onEndReached = useCallback(() => {
    if (hasNextPage && !isFetchingNextPage) void fetchNextPage();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  const renderItem = useCallback(
    ({ item }: { item: PlaceSummary }) => (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={item.name}
        onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: item.slug } })}
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      >
        <Ionicons name="location-outline" size={20} color={c.muted} />
        <View style={styles.body}>
          <Text style={styles.name} numberOfLines={1}>
            {item.name}
          </Text>
          <Text style={styles.sub} numberOfLines={1}>
            {[cuisinePriceLine(item.category, item.price_range), item.city].filter(Boolean).join(' · ')}
          </Text>
        </View>
      </Pressable>
    ),
    [styles, c.muted],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel="Go back" onPress={() => router.back()} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title} numberOfLines={1}>
          #{slug}
        </Text>
      </View>

      {isLoading ? (
        <View style={styles.center}>
          <ActivityIndicator color={c.primary} />
        </View>
      ) : isError ? (
        <View style={styles.center}>
          <Text style={styles.muted}>Couldn’t load places.</Text>
        </View>
      ) : (
        <FlashList
          data={items}
          keyExtractor={(item) => item.id}
          renderItem={renderItem}
          contentContainerStyle={styles.list}
          onEndReached={onEndReached}
          onEndReachedThreshold={0.5}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.muted}>No places for this tag yet.</Text>
            </View>
          }
          ListFooterComponent={
            isFetchingNextPage ? <ActivityIndicator style={styles.footer} color={c.primary} /> : null
          }
        />
      )}
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 10 },
    title: { fontFamily: fonts.display, fontSize: 22, fontWeight: '700', color: c.text, flex: 1, letterSpacing: -0.2 },
    list: { paddingHorizontal: 16, paddingBottom: 24 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
    pressed: { opacity: 0.6 },
    body: { flex: 1 },
    name: { fontFamily: fonts.display, fontSize: 16, fontWeight: '700', color: c.text },
    sub: { fontSize: 13, color: c.muted, textTransform: 'capitalize' },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 40 },
    muted: { color: c.muted, fontSize: 15 },
    footer: { paddingVertical: 20 },
  });
