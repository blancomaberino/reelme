import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { router } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useDismissShare } from '@/api/hooks/useDismissShare';
import { useFeed } from '@/api/hooks/useFeed';
import type { FeedItem } from '@/api/places';
import { FeedCard } from '@/components/feed/feed-card';
import { type MessageKey, useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

type T = (key: MessageKey) => string;

export default function FeedScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const authed = useSessionStore((s) => s.status === 'authed');
  const { data, isLoading, isError, refetch, isRefetching, fetchNextPage, hasNextPage, isFetchingNextPage } =
    useFeed('global');
  const { hide, undo } = useDismissShare('global');

  const items = useMemo(() => data?.pages.flatMap((p) => p.data) ?? [], [data]);

  const onPressCard = useCallback((slug: string) => {
    router.push({ pathname: '/place/[slug]', params: { slug } });
  }, []);

  // Undo snackbar: optimistically hide, keep the item ~5s for an undo.
  const [hidden, setHidden] = useState<FeedItem | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const doHide = useCallback(
    (item: FeedItem) => {
      hide.mutate(item.id);
      setHidden(item);
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => setHidden(null), 5000);
    },
    [hide],
  );
  // Confirm before hiding (from the ⋯ button or a swipe); undo still available.
  const onHide = useCallback(
    (item: FeedItem) => {
      Alert.alert(t('feed.hideConfirm.title'), t('feed.hideConfirm.message', { name: item.place.name }), [
        { text: t('common.cancel'), style: 'cancel' },
        { text: t('feed.hide'), style: 'destructive', onPress: () => doHide(item) },
      ]);
    },
    [doHide, t],
  );
  const onUndo = useCallback(() => {
    if (hidden) undo.mutate(hidden.id);
    if (timer.current) clearTimeout(timer.current);
    setHidden(null);
  }, [hidden, undo]);
  // Don't leave the auto-dismiss timer running after the screen unmounts.
  useEffect(() => () => void (timer.current && clearTimeout(timer.current)), []);

  const renderItem = useCallback(
    ({ item }: { item: FeedItem }) => (
      <FeedCard
        item={item}
        onPress={onPressCard}
        onHide={authed ? onHide : undefined}
        hideLabel={t('feed.hide')}
      />
    ),
    [onPressCard, onHide, authed, t],
  );

  const onEndReached = useCallback(() => {
    if (hasNextPage && !isFetchingNextPage) void fetchNextPage();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.title}>{t('feed.title')}</Text>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('feed.search')}
          onPress={() => router.push('/search')}
          hitSlop={10}
        >
          <Ionicons name="search" size={22} color={c.text} />
        </Pressable>
      </View>

      {isLoading ? (
        <View style={styles.center}>
          <ActivityIndicator color={c.primary} />
        </View>
      ) : isError ? (
        <ErrorState styles={styles} c={c} t={t} onRetry={() => void refetch()} />
      ) : (
        <FlashList
          data={items}
          renderItem={renderItem}
          keyExtractor={(item) => item.id}
          contentContainerStyle={styles.list}
          ItemSeparatorComponent={Separator}
          onEndReached={onEndReached}
          onEndReachedThreshold={0.5}
          refreshControl={
            <RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={c.primary} />
          }
          ListEmptyComponent={<EmptyState styles={styles} c={c} t={t} />}
          ListFooterComponent={
            isFetchingNextPage ? <ActivityIndicator style={styles.footer} color={c.primary} /> : null
          }
        />
      )}

      {hidden ? (
        <View style={styles.snackbar}>
          <Text style={styles.snackText}>{t('feed.hidden')}</Text>
          {/* Gate Undo until the hide POST settles so the DELETE can't race
              ahead of the row it's meant to remove. */}
          <Pressable accessibilityRole="button" onPress={onUndo} hitSlop={8} disabled={hide.isPending}>
            <Text style={[styles.snackUndo, hide.isPending && styles.snackUndoDisabled]}>{t('feed.undo')}</Text>
          </Pressable>
        </View>
      ) : null}
    </SafeAreaView>
  );
}

function Separator() {
  return <View style={styles12} />;
}
const styles12 = { height: 12 } as const;

function EmptyState({ styles, c, t }: { styles: Styles; c: Palette; t: T }) {
  return (
    <View style={styles.empty}>
      <Ionicons name="albums-outline" size={40} color={c.muted} />
      <Text style={styles.emptyTitle}>{t('feed.empty.title')}</Text>
      <Text style={styles.emptyBody}>{t('feed.empty.body')}</Text>
    </View>
  );
}

function ErrorState({ styles, c, t, onRetry }: { styles: Styles; c: Palette; t: T; onRetry: () => void }) {
  return (
    <View style={styles.center}>
      <Ionicons name="cloud-offline-outline" size={40} color={c.muted} />
      <Text style={styles.emptyTitle}>{t('feed.error.title')}</Text>
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
    list: { paddingHorizontal: 16, paddingBottom: 24 },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 10, padding: 32 },
    footer: { paddingVertical: 20 },
    empty: { alignItems: 'center', justifyContent: 'center', gap: 8, paddingTop: 80 },
    emptyTitle: { fontSize: 18, fontWeight: '700', color: c.text },
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
    snackbar: {
      position: 'absolute',
      left: 16,
      right: 16,
      bottom: 20,
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      backgroundColor: c.text,
      borderRadius: 12,
      paddingHorizontal: 16,
      paddingVertical: 12,
    },
    snackText: { color: c.background, fontSize: 14, fontWeight: '600' },
    snackUndo: { color: c.primary, fontSize: 14, fontWeight: '800' },
    snackUndoDisabled: { opacity: 0.5 },
  });
