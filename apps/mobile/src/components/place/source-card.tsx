import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { memo, useMemo } from 'react';
import { Pressable, type StyleProp, StyleSheet, Text, type TextStyle, View } from 'react-native';

import type { PlaceSourceItem } from '@/api/places';
import { useT } from '@/i18n';
import { platformIcon } from '@/lib/format';
import { openWebUrl } from '@/lib/linking';
import { type Palette, useColors } from '@/theme/colors';

import { Thumbnail } from './thumbnail';

type Props = {
  source: PlaceSourceItem;
};

/**
 * One provenance card on the place detail screen (T-033 §5): thumbnail,
 * platform badge, caption excerpt, and — the point — a link-out to the
 * original post.
 *
 * BOTH attribution handles tap through — the influencer to their public page,
 * the sharer to their Reelmap profile. They had been styled to look tappable
 * and left inert "until M3 profiles" since T-033; those shipped in T-036/T-039
 * and the wiring never followed, so they spent three milestones inviting a tap
 * and doing nothing.
 *
 * That stopped being a mere dead affordance once T-054 added blocking: a place
 * is where you encounter someone else's content, and the only other routes to a
 * profile are search (you must already know the handle) and a follow list. So
 * "you can block an abusive user" (Apple 1.2) meant retyping their username
 * somewhere else. This is the path from what you are looking at to the control
 * that acts on it.
 *
 * THE AFFORDANCE IS THE CHEVRON, NOT THE COLOUR. The first attempt tinted the
 * sharer's handle `primary` and the owner looked straight past it — reasonably,
 * because on the place screen `primary` already means the phone number, the
 * website, the Google link, the "shared first" badge, the ★, and the rating
 * stars. Colour there is decoration at least as often as it is a control.
 *
 * A trailing `chevron-forward` in `muted` is what this app already uses for
 * "tapping this navigates" — follow-list rows, ShareRow, every settings row,
 * each rendering it ONLY when the row is tappable. Reused verbatim so the
 * meaning is one somebody has already learned. Weight still encodes ROLE
 * (credited creator vs. sharer); the chevron encodes navigability. One signal
 * each, rather than colour trying to carry both and landing neither.
 */
function SourceCardBase({ source }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { source_post: post, influencer, sharer } = source;
  const open = () => openWebUrl(post.url);

  // A private sharer is anonymized by the API (null) — never crash on it, and
  // never render a tap target that would navigate to `/users/undefined`.
  const sharerLabel = sharer ? `@${sharer.username}` : t('feed.sharerFallback');
  const openSharer = sharer
    ? () => router.push({ pathname: '/users/[username]', params: { username: sharer.username } })
    : undefined;
  const openInfluencer = influencer
    ? () => router.push({ pathname: '/influencer/[id]', params: { id: influencer.id } })
    : undefined;

  return (
    <Pressable
      accessibilityRole="link"
      accessibilityLabel={t('source.openOriginal', { platform: post.platform })}
      onPress={open}
      style={({ pressed }) => [styles.card, pressed ? styles.pressed : null]}
    >
      <View style={styles.top}>
        <Thumbnail uri={post.thumbnail_url} style={styles.thumb} />
        <View style={styles.body}>
          <View style={styles.badgeRow}>
            <Ionicons name={platformIcon(post.platform)} size={16} color={c.muted} />
            <Text style={styles.platform}>{post.platform}</Text>
            {source.is_primary ? <Text style={styles.firstShared}>{t('source.firstShared')}</Text> : null}
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
          <AttrLink
            icon="star"
            iconColor={c.primary}
            label={`@${influencer.handle}`}
            textStyle={styles.attrText}
            onPress={openInfluencer}
            accessibilityLabel={t('source.openInfluencer', { handle: `@${influencer.handle}` })}
            testID={`source-influencer-${influencer.handle}`}
            c={c}
            styles={styles}
          />
        ) : null}
        <AttrLink
          icon="person-outline"
          iconColor={c.muted}
          label={sharerLabel}
          textStyle={styles.attrMuted}
          onPress={openSharer}
          accessibilityLabel={t('source.openSharer', { handle: sharerLabel })}
          testID={sharer ? `source-sharer-${sharer.username}` : undefined}
          c={c}
          styles={styles}
        />
      </View>
    </Pressable>
  );
}

/**
 * One attribution handle: icon, handle, and — only when it goes somewhere — the
 * chevron this app uses everywhere else to mean "tapping this navigates".
 *
 * A nested Pressable INSIDE the card's own: React Native hands the touch to the
 * innermost responder, so tapping a handle opens that person and tapping
 * anywhere else on the card still opens the original post. Verified on device,
 * because that nesting is exactly the kind of thing that works under a
 * synthetic press and gets swallowed by a real view hierarchy.
 */
function AttrLink({
  icon,
  iconColor,
  label,
  textStyle,
  onPress,
  accessibilityLabel,
  testID,
  c,
  styles,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  iconColor: string;
  label: string;
  textStyle: StyleProp<TextStyle>;
  onPress?: () => void;
  accessibilityLabel: string;
  testID?: string;
  c: Palette;
  styles: ReturnType<typeof makeStyles>;
}) {
  return (
    <Pressable
      // `text`, not a disabled button, when there is nowhere to go — a private
      // sharer is still information worth announcing, just not a control.
      accessibilityRole={onPress ? 'button' : 'text'}
      accessibilityLabel={onPress ? accessibilityLabel : label}
      disabled={onPress === undefined}
      onPress={onPress}
      // The row is 13pt type in a card footer; without this the tap target is
      // roughly half the 44pt minimum.
      hitSlop={10}
      style={({ pressed }) => [styles.attrItem, pressed && onPress ? styles.pressed : null]}
      testID={testID}
    >
      <Ionicons name={icon} size={13} color={iconColor} />
      <Text style={textStyle} numberOfLines={1}>
        {label}
      </Text>
      {onPress ? <Ionicons name="chevron-forward" size={13} color={c.muted} /> : null}
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
    // `paddingVertical` on top of the hitSlop: together they clear the 44pt
    // minimum without moving the row's visual position.
    attrItem: { flexDirection: 'row', alignItems: 'center', gap: 5, paddingVertical: 6 },
    attrText: { color: c.text, fontSize: 13, fontWeight: '600' },
    attrMuted: { color: c.muted, fontSize: 13 },
  });
