import { Ionicons } from '@expo/vector-icons';
import { memo, useMemo } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';

import type { PlaceSourceItem } from '@/api/places';
import { platformIcon } from '@/lib/format';
import { type Palette, useColors } from '@/theme/colors';

import { Thumbnail } from './thumbnail';

type Props = {
  source: PlaceSourceItem;
};

/**
 * One provenance card on the place detail screen (T-033 §5): thumbnail,
 * platform badge, caption excerpt, and — the point — a link-out to the
 * original post. Attribution rows (influencer + sharer) render tappable-looking
 * but inert until M3 profiles (T-036/T-039).
 */
function SourceCardBase({ source }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { source_post: post, influencer, sharer } = source;
  const open = () => {
    void Linking.openURL(post.url);
  };

  // A private sharer is anonymized by the API (null) — never crash on it.
  const sharerLabel = sharer ? `@${sharer.username}` : 'a Reelmap user';

  return (
    <Pressable
      accessibilityRole="link"
      accessibilityLabel={`Open original ${post.platform} post`}
      onPress={open}
      style={({ pressed }) => [styles.card, pressed ? styles.pressed : null]}
    >
      <View style={styles.top}>
        <Thumbnail uri={post.thumbnail_url} style={styles.thumb} />
        <View style={styles.body}>
          <View style={styles.badgeRow}>
            <Ionicons name={platformIcon(post.platform)} size={16} color={c.muted} />
            <Text style={styles.platform}>{post.platform}</Text>
            {source.is_primary ? <Text style={styles.firstShared}>First shared</Text> : null}
            <Ionicons name="open-outline" size={15} color={c.muted} style={styles.openIcon} />
          </View>
          {post.caption ? (
            <Text style={styles.caption} numberOfLines={3}>
              {post.caption}
            </Text>
          ) : null}
        </View>
      </View>

      <View style={styles.attribution}>
        {influencer ? (
          <View style={styles.attrItem}>
            <Ionicons name="star" size={13} color={c.primary} />
            <Text style={styles.attrText} numberOfLines={1}>
              @{influencer.handle}
            </Text>
          </View>
        ) : null}
        <View style={styles.attrItem}>
          <Ionicons name="person-outline" size={13} color={c.muted} />
          <Text style={styles.attrMuted} numberOfLines={1}>
            {sharerLabel}
          </Text>
        </View>
      </View>
    </Pressable>
  );
}

export const SourceCard = memo(SourceCardBase);

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    card: {
      backgroundColor: c.surface,
      borderRadius: 16,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: 12,
      gap: 10,
    },
    pressed: { opacity: 0.7 },
    top: { flexDirection: 'row', gap: 12 },
    thumb: { width: 64, height: 64, borderRadius: 12 },
    body: { flex: 1, gap: 4 },
    badgeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
    platform: { color: c.muted, fontSize: 13, fontWeight: '600', textTransform: 'capitalize' },
    firstShared: {
      color: c.primary,
      fontSize: 11,
      fontWeight: '700',
      backgroundColor: c.primarySoft,
      paddingHorizontal: 6,
      paddingVertical: 2,
      borderRadius: 6,
      overflow: 'hidden',
    },
    openIcon: { marginLeft: 'auto' },
    caption: { color: c.text, fontSize: 14, lineHeight: 19 },
    attribution: {
      flexDirection: 'row',
      flexWrap: 'wrap',
      gap: 14,
      borderTopWidth: StyleSheet.hairlineWidth,
      borderTopColor: c.border,
      paddingTop: 8,
    },
    attrItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
    attrText: { color: c.text, fontSize: 13, fontWeight: '600' },
    attrMuted: { color: c.muted, fontSize: 13 },
  });
