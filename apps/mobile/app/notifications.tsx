import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { Stack, router } from 'expo-router';
import { useCallback, useMemo } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useMarkRead, useNotifications } from '@/api/hooks/useNotifications';
import type { NotificationRow } from '@/api/notifications';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { elapsedSince } from '@/lib/format';
import { notificationCopy } from '@/notifications/copy';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * The notification center (T-040, 05 screen #23).
 *
 * A push is an interruption; this is the record. Everything the pipeline and
 * the social graph emit lands here with the same `{type, url, title, body}`
 * payload, so a push the user dismissed on the lock screen is still findable.
 *
 * Rows route through `data.url` — the identical path the push tap handler uses
 * (05 §5.2). One routing contract, no per-type switch, which is what keeps a
 * type M4 adds working here before this screen knows about it.
 *
 * Copy is resolved by {@see notificationCopy} from `type` + the params on the
 * row, NOT read off the row's stored `title`/`body`. Those are written once by
 * a worker in whatever language the account was set to that day and then frozen;
 * rendering them meant the list mixed languages and printed the raw machine
 * string for any row that predated them.
 */

/** Icon per known type; anything else falls back to a neutral bell. */
const ICONS: Record<string, keyof typeof Ionicons.glyphMap> = {
  'share.published': 'checkmark-circle',
  'share.review_needed': 'help-circle',
  'share.failed': 'alert-circle',
  'social.follow': 'person-add',
  'influencer.claim_rejected': 'close-circle',
  'redemption.verified': 'ticket',
  'wallet.payout': 'cash',
};

/** Colour per known type — muted by default so only outcomes carry hue. */
function tintFor(c: Palette, type: string): string {
  if (type === 'share.published' || type === 'redemption.verified') return c.green;
  if (type === 'share.failed' || type === 'influencer.claim_rejected') return c.danger;
  if (type === 'share.review_needed') return c.gold;
  return c.secondary;
}

type Row = { kind: 'header'; label: string } | { kind: 'row'; item: NotificationRow };

export default function NotificationsScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data, isLoading, isError, refetch, isRefetching, fetchNextPage, hasNextPage, isFetchingNextPage } =
    useNotifications();
  const markRead = useMarkRead();

  const items = useMemo(() => data?.pages.flatMap((p) => p.data) ?? [], [data]);
  const unread = data?.pages[0]?.meta.unread_count ?? 0;

  /**
   * Two sections — "New" and "Earlier" — flattened into one list rather than a
   * SectionList, because FlashList's recycling is what keeps a long history
   * cheap and a section header is just another row type.
   */
  const rows = useMemo<Row[]>(() => {
    const newOnes = items.filter((i) => i.read_at === null);
    const earlier = items.filter((i) => i.read_at !== null);
    const out: Row[] = [];
    if (newOnes.length > 0) {
      out.push({ kind: 'header', label: t('notifications.new') });
      out.push(...newOnes.map((item) => ({ kind: 'row' as const, item })));
    }
    if (earlier.length > 0) {
      out.push({ kind: 'header', label: t('notifications.earlier') });
      out.push(...earlier.map((item) => ({ kind: 'row' as const, item })));
    }
    return out;
  }, [items, t]);

  const onPressRow = useCallback(
    (item: NotificationRow) => {
      // Mark read first: the navigation unmounts this screen, and a mutation
      // fired after that still completes but has no list left to update
      // optimistically.
      if (item.read_at === null) markRead.mutate({ ids: [item.id] });
      if (item.url) router.push(item.url as never);
    },
    [markRead],
  );

  const onEndReached = useCallback(() => {
    if (hasNextPage && !isFetchingNextPage) void fetchNextPage();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  const renderItem = useCallback(
    ({ item }: { item: Row }) =>
      item.kind === 'header' ? (
        <Text style={styles.sectionHeader}>{item.label}</Text>
      ) : (
        <NotificationCard item={item.item} styles={styles} c={c} t={t} onPress={onPressRow} />
      ),
    [styles, c, t, onPressRow],
  );

  /*
   * Two row shapes, so FlashList must be told which is which. Without it the
   * recycler hands a header's view to a notification card (and back), because
   * it assumes one homogeneous type — the failure looks like rows rendering at
   * the wrong height or briefly showing the wrong content while scrolling.
   */
  const getItemType = useCallback((row: Row) => row.kind, []);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader
        title={t('notifications.title')}
        divided
        right={
          unread > 0 ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('notifications.markAll')}
              onPress={() => markRead.mutate({ all: true })}
              hitSlop={10}
              testID="mark-all-read"
            >
              <Text style={styles.markAll}>{t('notifications.markAll')}</Text>
            </Pressable>
          ) : undefined
        }
      />

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : isError ? (
        <View style={styles.empty} testID="notifications-error">
          <Ionicons name="alert-circle-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('notifications.error')}</Text>
          <Pressable accessibilityRole="button" onPress={() => void refetch()} style={styles.retry}>
            <Text style={styles.retryText}>{t('common.tryAgain')}</Text>
          </Pressable>
        </View>
      ) : (
        <FlashList
          data={rows}
          renderItem={renderItem}
          getItemType={getItemType}
          keyExtractor={(row) => (row.kind === 'header' ? `h-${row.label}` : row.item.id)}
          contentContainerStyle={styles.list}
          onEndReached={onEndReached}
          onEndReachedThreshold={0.5}
          refreshControl={
            <RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={c.primary} />
          }
          ListEmptyComponent={
            <View style={styles.empty} testID="notifications-empty">
              <Ionicons name="notifications-outline" size={40} color={c.muted} />
              <Text style={styles.emptyText}>{t('notifications.empty')}</Text>
            </View>
          }
          ListFooterComponent={isFetchingNextPage ? <ActivityIndicator style={styles.footer} color={c.primary} /> : null}
        />
      )}
    </SafeAreaView>
  );
}

function NotificationCard({
  item,
  styles,
  c,
  t,
  onPress,
}: {
  item: NotificationRow;
  styles: Styles;
  c: Palette;
  t: ReturnType<typeof useT>;
  onPress: (item: NotificationRow) => void;
}) {
  const unread = item.read_at === null;
  // Resolved in the CURRENT language from `type` + params — an unknown type from
  // a newer server still renders, falling back to the copy that server sent.
  const { title, body } = notificationCopy(t, item);
  const elapsed = elapsedSince(item.created_at);
  const when = elapsed
    ? elapsed.unit === 'now'
      ? t('time.now')
      : t(`time.${elapsed.unit}`, { count: elapsed.value })
    : null;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={body ? `${title}. ${body}` : title}
      accessibilityState={{ selected: unread }}
      onPress={() => onPress(item)}
      style={({ pressed }) => [styles.row, unread && styles.rowUnread, pressed && styles.pressed]}
      testID={`notification-${item.id}`}
    >
      <Ionicons name={ICONS[item.type] ?? 'notifications'} size={22} color={tintFor(c, item.type)} />
      <View style={styles.rowBody}>
        <Text style={styles.rowTitle} numberOfLines={1}>
          {title}
        </Text>
        {body ? (
          <Text style={styles.rowText} numberOfLines={2}>
            {body}
          </Text>
        ) : null}
      </View>
      {/* "3d" answers the question every notification list gets asked — is this
          new, or have I already dealt with it? The unread dot alone doesn't. */}
      {when ? <Text style={styles.rowWhen}>{when}</Text> : null}
      {unread ? <View style={styles.dot} testID="unread-dot" /> : null}
    </Pressable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { paddingVertical: space.xl },
    // xxl, matching the offers list: `edges` covers only the top, so the last
    // row needs room to clear the home indicator instead of ending under it.
    list: { paddingHorizontal: space.md, paddingBottom: space.xxl },
    markAll: { ...type.bodySm, color: c.primary, fontWeight: '700' },
    sectionHeader: {
      ...type.caption,
      color: c.muted,
      textTransform: 'uppercase',
      letterSpacing: 0.4,
      paddingTop: space.md,
      paddingBottom: space.xs,
    },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      paddingVertical: space.sm,
      paddingHorizontal: space.sm,
      borderRadius: radius.md,
    },
    rowUnread: { backgroundColor: c.surface },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1, gap: space.xxs },
    rowTitle: { ...type.bodyLg, color: c.text },
    rowText: { ...type.bodySm, color: c.muted },
    rowWhen: { ...type.caption, color: c.muted },
    dot: { width: 8, height: 8, borderRadius: radius.pill, backgroundColor: c.primary },
    footer: { paddingVertical: space.md },
    empty: { alignItems: 'center', gap: space.xs, paddingTop: space.xxl, paddingHorizontal: space.xl },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },
    retry: {
      marginTop: space.sm,
      paddingHorizontal: space.lg,
      paddingVertical: space.xs,
      borderRadius: radius.md,
      borderWidth: 1.5,
      borderColor: c.primary,
    },
    retryText: { ...type.body, color: c.primary, fontWeight: '600' },
  });
