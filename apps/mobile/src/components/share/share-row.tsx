import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { ShareDetail, ShareStatus } from '@/api/shares';
import { type MessageKey, useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

const STATUS_KEY: Record<ShareStatus, MessageKey> = {
  pending: 'share.status.pending',
  fetching: 'share.status.fetching',
  analyzing: 'share.status.analyzing',
  review: 'share.status.review',
  published: 'share.status.published',
  failed: 'share.status.failed',
  rejected: 'share.status.rejected',
};

/** Soft-bg / fg pill colors for a share's lifecycle state. */
function statusTone(status: ShareStatus, c: Palette): { bg: string; fg: string } {
  if (status === 'published') return { bg: c.greenSoft, fg: c.green };
  if (status === 'review') return { bg: c.goldSoft, fg: c.gold };
  if (status === 'failed' || status === 'rejected') return { bg: c.dangerSoft, fg: c.danger };
  return { bg: c.primarySoft, fg: c.primary };
}

/**
 * One row of the viewer's ingest history — label + live status pill — shared by
 * the composer's "Recent shares" and the "My shares" list. A published share
 * opens its pin; anything still in flight, in review, or failed opens the status
 * screen so it can be watched or resumed (T-026).
 */
export function ShareRow({ share }: { share: ShareDetail }) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const label =
    share.place?.name ?? share.source_post.caption ?? share.source_post.url?.replace(/^https?:\/\//, '') ?? '—';
  const tone = statusTone(share.status, c);
  const go =
    share.place != null
      ? () => router.push({ pathname: '/place/[slug]', params: { slug: share.place!.id } })
      : () => router.push({ pathname: '/shares/[id]/status', params: { id: share.id } });

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={go}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      <Text style={styles.label} numberOfLines={1}>
        {label}
      </Text>
      <View style={[styles.pill, { backgroundColor: tone.bg }]}>
        <Text style={[styles.pillText, { color: tone.fg }]}>{t(STATUS_KEY[share.status])}</Text>
      </View>
      <Ionicons name="chevron-forward" size={16} color={c.muted} />
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingVertical: 12,
      paddingHorizontal: 4,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    pressed: { opacity: 0.6 },
    label: { flex: 1, fontSize: 15, color: c.text },
    pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
    pillText: { fontSize: 12, fontWeight: '700' },
  });
