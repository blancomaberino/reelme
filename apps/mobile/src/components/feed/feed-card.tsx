import { Ionicons } from '@expo/vector-icons';
import { memo, useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { FeedItem } from '@/api/places';
import { Thumbnail } from '@/components/place/thumbnail';
import { cuisinePriceLine, platformIcon, relativeTime } from '@/lib/format';
import { type Palette, useColors } from '@/theme/colors';

type Props = {
  item: FeedItem;
  onPress: (slug: string) => void;
};

/** One feed row (T-034): thumbnail, place + cuisine/price, attribution, time. */
function FeedCardBase({ item, onPress }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { place, source_post: post, influencer, sharer } = item;
  const line = cuisinePriceLine(place.category, place.price_range);
  const sharerLabel = sharer ? `@${sharer.username}` : 'a Reelmap user';

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
        <View style={styles.attrRow}>
          <Ionicons name={platformIcon(post.platform)} size={13} color={c.muted} />
          {influencer ? (
            <Text style={styles.attr} numberOfLines={1}>
              @{influencer.handle}
            </Text>
          ) : null}
          <Text style={styles.muted} numberOfLines={1}>
            · {sharerLabel}
          </Text>
          <Text style={styles.time}>{relativeTime(item.published_at)}</Text>
        </View>
      </View>
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
    name: { fontSize: 16, fontWeight: '700', color: c.text },
    metaRow: { flexDirection: 'row', gap: 8, alignItems: 'center', flexWrap: 'wrap' },
    meta: { fontSize: 14, color: c.text, textTransform: 'capitalize' },
    muted: { fontSize: 13, color: c.muted },
    attrRow: { flexDirection: 'row', gap: 5, alignItems: 'center' },
    attr: { fontSize: 13, color: c.text, fontWeight: '600', maxWidth: 120 },
    time: { fontSize: 12, color: c.muted, marginLeft: 'auto' },
  });
