import { Ionicons } from '@expo/vector-icons';
import { memo, useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { FeedItem } from '@/api/places';
import { Thumbnail } from '@/components/place/thumbnail';
import { useT } from '@/i18n';
import { cuisinePriceLine, platformIcon, relativeTime } from '@/lib/format';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  item: FeedItem;
  onPress: (slug: string) => void;
  /** When provided (authed viewers), shows a ⋯ button to hide the item. */
  onHide?: (item: FeedItem) => void;
  /** Accessibility label for the hide control (localized by the caller). */
  hideLabel?: string;
};

/** One feed row (T-034): thumbnail, place + cuisine/price, attribution, time. */
function FeedCardBase({ item, onPress, onHide, hideLabel }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { place, source_post: post, influencer, sharer } = item;
  const line = cuisinePriceLine(place.category, place.price_range);
  const sharerLabel = sharer ? `@${sharer.username}` : t('feed.sharerFallback');

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={place.name}
      onPress={() => onPress(place.slug)}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      <Thumbnail uri={post.thumbnail_url} style={styles.thumb} />
      <View style={styles.body}>
        <Text style={styles.name} numberOfLines={1}>
          {place.name}
        </Text>
        <View style={styles.metaRow}>
          {line ? <Text style={styles.meta}>{line}</Text> : null}
          {place.city ? <Text style={styles.muted}>{place.city}</Text> : null}
        </View>
        {/* Attribution: the influencer + sharer group shrinks/truncates so a
            long username can't push the row (and the timestamp) off-screen. */}
        <View style={styles.attrRow}>
          <Ionicons name={platformIcon(post.platform)} size={13} color={c.muted} />
          <View style={styles.attrGroup}>
            {influencer ? (
              <Text style={styles.attr} numberOfLines={1}>
                @{influencer.handle}
              </Text>
            ) : null}
            <Text style={styles.sharer} numberOfLines={1}>
              · {sharerLabel}
            </Text>
          </View>
          <Text style={styles.time}>{relativeTime(item.published_at)}</Text>
        </View>
        {place.rating?.google?.value != null ? (
          <View style={styles.ratingChip}>
            <Text style={styles.ratingText}>★ {place.rating.google.value.toFixed(1)}</Text>
          </View>
        ) : null}
      </View>
      {onHide ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={hideLabel ?? 'Hide from my feed'}
          hitSlop={10}
          onPress={() => onHide(item)}
          style={styles.more}
        >
          <Ionicons name="ellipsis-horizontal" size={18} color={c.muted} />
        </Pressable>
      ) : null}
    </Pressable>
  );
}

export const FeedCard = memo(FeedCardBase);

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    card: {
      flexDirection: 'row',
      gap: 12,
      padding: 12,
      backgroundColor: c.surface,
      borderRadius: 16,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    pressed: { opacity: 0.7 },
    thumb: { width: 72, height: 72, borderRadius: 12 },
    body: { flex: 1, gap: 4, justifyContent: 'center' },
    // paddingRight keeps a long (truncated) name clear of the absolute ⋯ button.
    name: { fontFamily: fonts.display, fontSize: 17, fontWeight: '700', color: c.text, letterSpacing: -0.2, paddingRight: 24 },
    ratingChip: { alignSelf: 'flex-start', backgroundColor: c.goldSoft, borderRadius: 6, paddingHorizontal: 6, paddingVertical: 2, marginTop: 2 },
    ratingText: { color: c.gold, fontSize: 11, fontWeight: '700' },
    metaRow: { flexDirection: 'row', gap: 8, alignItems: 'center', flexWrap: 'wrap' },
    meta: { fontSize: 14, color: c.text, textTransform: 'capitalize' },
    muted: { fontSize: 13, color: c.muted },
    attrRow: { flexDirection: 'row', gap: 5, alignItems: 'center' },
    // flex + minWidth:0 lets the group shrink so its children can truncate.
    attrGroup: { flex: 1, minWidth: 0, flexDirection: 'row', alignItems: 'center', gap: 5 },
    attr: { fontSize: 13, color: c.text, fontWeight: '600', flexShrink: 1, maxWidth: 120 },
    sharer: { fontSize: 13, color: c.muted, flexShrink: 1 },
    time: { fontSize: 12, color: c.muted },
    more: { position: 'absolute', top: 8, right: 8, padding: 4 },
  });
