import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { Stack, router } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Animated, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { RectButton, Swipeable } from 'react-native-gesture-handler';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useMarkRead, useNotifications } from '@/api/hooks/useNotifications';
import type { NotificationRow } from '@/api/notifications';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { elapsedSince } from '@/lib/format';
import { useReduceMotion } from '@/lib/use-reduce-motion';
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
   * Which rows were unread when this screen first laid eyes on them.
   *
   * Sectioning reads from THIS, not from live `read_at`, and that is the whole
   * point: splitting on the live value meant clearing a row re-sorted it into
   * "Earlier" on the spot, so everything below jumped up by a row height under
   * the user's thumb. You tap a dot and the list moves — which reads as the
   * screen scrolling itself.
   *
   * Freezing it means marking read is a purely visual change in place. The
   * sections re-settle on a pull-to-refresh, which is the one moment the user
   * has asked for the list to be rebuilt and expects it to move.
   */
  const settled = useRef(new Set<string>());
  const wasUnread = useRef(new Set<string>());

  /**
   * Two sections — "New" and "Earlier" — flattened into one list rather than a
   * SectionList, because FlashList's recycling is what keeps a long history
   * cheap and a section header is just another row type.
   */
  const rows = useMemo<Row[]>(() => {
    // Classify each row once, on first sight — including rows that arrive later
    // from a next-page fetch.
    for (const item of items) {
      if (settled.current.has(item.id)) continue;
      settled.current.add(item.id);
      if (item.read_at === null) wasUnread.current.add(item.id);
    }

    const newOnes = items.filter((i) => wasUnread.current.has(i.id));
    const earlier = items.filter((i) => !wasUnread.current.has(i.id));
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

  /** Clear one row's unread state without opening it. */
  const onMarkRead = useCallback(
    (item: NotificationRow) => {
      markRead.mutate({ ids: [item.id] });
    },
    [markRead],
  );

  const onEndReached = useCallback(() => {
    if (hasNextPage && !isFetchingNextPage) void fetchNextPage();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  /**
   * A pull-to-refresh is the moment the frozen sections are allowed to move:
   * the user asked for the list to be rebuilt, so rows they cleared settling
   * into "Earlier" is the expected result rather than a jump they didn't cause.
   */
  const onRefresh = useCallback(() => {
    settled.current.clear();
    wasUnread.current.clear();
    void refetch();
  }, [refetch]);

  const renderItem = useCallback(
    ({ item }: { item: Row }) =>
      item.kind === 'header' ? (
        <Text style={styles.sectionHeader}>{item.label}</Text>
      ) : (
        <NotificationCard
          item={item.item}
          styles={styles}
          c={c}
          t={t}
          onPress={onPressRow}
          onMarkRead={onMarkRead}
        />
      ),
    [styles, c, t, onPressRow, onMarkRead],
  );

  /*
   * THREE row shapes, and the recycler has to know all three apart: a section
   * header, an unread row (wrapped in a `Swipeable`), and a read row (a bare
   * `Pressable`). Anything the recycler considers one type it will hand another
   * item's view to.
   *
   * Splitting unread from read is not cosmetic: an unread row is a `Swipeable`
   * wrapping a `Pressable`, a read row is the `Pressable` alone. Handing the
   * recycler one where it expects the other makes it reconcile two different
   * trees in a reused cell — and marking a row read flips it from one shape to
   * the other, so this happens on the most ordinary interaction the screen has.
   */
  const getItemType = useCallback(
    (row: Row) => (row.kind === 'header' ? 'header' : row.item.read_at === null ? 'row-unread' : 'row-read'),
    [],
  );

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
          // 12pt, the same separator the my-places list uses — unread rows are
          // cards, and without a gap they fused into one continuous slab.
          ItemSeparatorComponent={Separator}
          keyExtractor={(row) => (row.kind === 'header' ? `h-${row.label}` : row.item.id)}
          contentContainerStyle={styles.list}
          onEndReached={onEndReached}
          onEndReachedThreshold={0.5}
          refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={onRefresh} tintColor={c.primary} />}
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

/** The gap between rows. A component because FlashList wants an element type. */
function Separator() {
  return <View style={separatorStyle} />;
}
const separatorStyle = { height: space.sm } as const;

function NotificationCard({
  item,
  styles,
  c,
  t,
  onPress,
  onMarkRead,
}: {
  item: NotificationRow;
  styles: Styles;
  c: Palette;
  t: ReturnType<typeof useT>;
  onPress: (item: NotificationRow) => void;
  onMarkRead: (item: NotificationRow) => void;
}) {
  const unread = item.read_at === null;
  const swipeRef = useRef<Swipeable>(null);
  const markLabel = t('notifications.markRead');
  const reduceMotion = useReduceMotion();

  /**
   * 0 = unread, 1 = read. Clearing a row is a CROSS-FADE in place, not a
   * disappearance and not a re-sort: the card's surface and border melt into
   * the page and the dot fades with them, leaving the text exactly where it
   * was. Nothing below it moves.
   */
  const progress = useRef(new Animated.Value(unread ? 0 : 1)).current;
  // Kept mounted across the fade so the dot can fade out rather than vanish.
  const [showDot, setShowDot] = useState(unread);

  useEffect(() => {
    if (unread) {
      progress.setValue(0);
      setShowDot(true);

      return;
    }

    // `reduceMotion !== false`, never `!reduceMotion` — it is `undefined` until
    // the OS setting has been read, and treating that as "animate" is the flash
    // of motion the setting exists to prevent.
    if (reduceMotion !== false) {
      progress.setValue(1);
      setShowDot(false);

      return;
    }

    const animation = Animated.timing(progress, {
      toValue: 1,
      duration: 240,
      // Colours cannot be driven natively.
      useNativeDriver: false,
    });
    animation.start(({ finished }) => {
      if (finished) setShowDot(false);
    });

    return () => animation.stop();
  }, [unread, reduceMotion, progress]);

  const chrome = useMemo(
    () => ({
      // Fades to the PAGE colour rather than to `transparent`: interpolating to
      // transparent runs through rgba(0,0,0,0), so the card darkens on its way
      // out instead of dissolving.
      background: progress.interpolate({ inputRange: [0, 1], outputRange: [c.surface, c.background] }),
      border: progress.interpolate({ inputRange: [0, 1], outputRange: [c.border, c.background] }),
      dot: progress.interpolate({ inputRange: [0, 1], outputRange: [1, 0] }),
    }),
    [progress, c],
  );

  const markRead = () => {
    swipeRef.current?.close();
    onMarkRead(item);
  };

  /**
   * Left-swipe reveals "mark read", mirroring the my-places card (T-071) —
   * same `Swipeable` mechanics, same friction and threshold — but in `primary`
   * rather than `danger`, because clearing a badge is not a destructive act and
   * should not borrow the colour that means "this deletes something".
   */
  const renderRightActions = (
    _progress: Animated.AnimatedInterpolation<number>,
    dragX: Animated.AnimatedInterpolation<number>,
  ) => {
    // Same interpolation ranges as the my-places card, so the two swipes feel
    // identical under the thumb rather than merely looking alike.
    const scale = dragX.interpolate({ inputRange: [-96, -24, 0], outputRange: [1, 0.86, 0.4], extrapolate: 'clamp' });
    const opacity = dragX.interpolate({ inputRange: [-72, -24, 0], outputRange: [1, 0.5, 0], extrapolate: 'clamp' });

    return (
      <RectButton
        accessibilityLabel={markLabel}
        onPress={markRead}
        style={styles.swipeAction}
        testID={`mark-read-swipe-${item.id}`}
      >
        <Animated.View style={[styles.swipeInner, { opacity, transform: [{ scale }] }]}>
          <Ionicons name="mail-open-outline" size={22} color={c.onPrimary} />
          <Text style={styles.swipeText}>{markLabel}</Text>
        </Animated.View>
      </RectButton>
    );
  };
  // Resolved in the CURRENT language from `type` + params — an unknown type from
  // a newer server still renders, falling back to the copy that server sent.
  const { title, body } = notificationCopy(t, item);
  const elapsed = elapsedSince(item.created_at);
  // `now` needs no count, but passing one is harmless — `time.now` has no
  // placeholder to fill — so the unit maps straight to its key with no special
  // case.
  const when = elapsed ? t(`time.${elapsed.unit}`, { count: elapsed.value }) : null;

  const card = (
    <Animated.View
      style={[
        styles.rowChrome,
        { backgroundColor: chrome.background, borderColor: chrome.border },
      ]}
    >
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={body ? `${title}. ${body}` : title}
        accessibilityState={{ selected: unread }}
        onPress={() => onPress(item)}
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}
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
        {/*
          The dot is both the unread indicator and the button that clears it —
          tapping the thing that means "unread" to make it not-unread needs no
          explaining. It is also the accessible route to the action: a swipe is
          invisible to a screen reader and undiscoverable to most people, so it
          can be the shortcut but never the only way in.

          It stays mounted for the length of the fade so it can fade WITH the
          card, but stops being a button the instant the row is read — an
          invisible control that still answers taps is worse than no control.
        */}
        {showDot ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={markLabel}
            onPress={markRead}
            disabled={!unread}
            accessibilityElementsHidden={!unread}
            importantForAccessibility={unread ? 'yes' : 'no-hide-descendants'}
            hitSlop={12}
            style={styles.dotHit}
            testID={`mark-read-${item.id}`}
          >
            <Animated.View style={[styles.dot, { opacity: chrome.dot }]} testID="unread-dot" />
          </Pressable>
        ) : null}
      </Pressable>
    </Animated.View>
  );

  // A read row has nothing to mark, so it gets no swipe — an action that
  // silently does nothing is worse than no action.
  if (!unread) return card;

  return (
    <Swipeable
      /*
       * Keyed by the row id so a recycled cell gets a FRESH Swipeable rather
       * than one carrying another notification's gesture state. `Swipeable`
       * keeps open/closed position internally, and FlashList reuses cells as
       * you scroll — without this, a row can inherit a half-open offset from
       * whichever item last used that cell.
       */
      key={item.id}
      ref={swipeRef}
      friction={2}
      rightThreshold={44}
      overshootRight={false}
      renderRightActions={renderRightActions}
    >
      {card}
    </Swipeable>
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
      // No bottom padding: the 12pt separator already sits between the header
      // and its first row, so adding both stacked 20pt in there.
      paddingTop: space.sm,
    },
    /*
     * The card's SKIN, split from its layout because only the skin animates.
     * The hairline is what makes a separated row read as a card rather than a
     * floating patch of white — the same treatment the my-places card uses —
     * and it is always present, fading its colour to the page rather than
     * toggling `borderWidth`, which would shift the content by a pixel.
     */
    rowChrome: {
      borderRadius: radius.md,
      borderWidth: StyleSheet.hairlineWidth,
    },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      paddingVertical: space.sm,
      paddingHorizontal: space.sm,
    },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1, gap: space.xxs },
    rowTitle: { ...type.bodyLg, color: c.text },
    rowText: { ...type.bodySm, color: c.muted },
    rowWhen: { ...type.caption, color: c.muted },
    // The visual dot stays 8pt; the padding around it is what makes the tap
    // target reachable without the indicator growing into a blob.
    dotHit: { padding: space.xs },
    dot: { width: 8, height: 8, borderRadius: radius.pill, backgroundColor: c.primary },
    swipeAction: {
      width: 108,
      marginLeft: space.xs,
      borderRadius: radius.md,
      backgroundColor: c.primary,
      alignItems: 'center',
      justifyContent: 'center',
    },
    swipeInner: { alignItems: 'center', gap: space.xxs, paddingHorizontal: space.xs },
    swipeText: { ...type.caption, color: c.onPrimary, fontWeight: '700', textAlign: 'center' },
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
