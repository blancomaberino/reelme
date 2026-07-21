import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useShares } from '@/api/hooks/useShares';
import type { ShareDetail, ShareStatus } from '@/api/shares';
import { type MessageKey, useT } from '@/i18n';
import { safeBack } from '@/lib/nav';
import { fonts, type Palette, useColors } from '@/theme/colors';

const STATUS_KEY: Record<ShareStatus, MessageKey> = {
  pending: 'share.status.pending',
  fetching: 'share.status.fetching',
  analyzing: 'share.status.analyzing',
  review: 'share.status.review',
  published: 'share.status.published',
  failed: 'share.status.failed',
  rejected: 'share.status.rejected',
};

/**
 * "My shares" — the ingest history, reachable from Profile (T-026). Published
 * shares open their pin; anything still in flight, in review, or failed opens the
 * status/detail screen so it can be resumed. This is the re-entry point the review
 * flow needs now that there's no Share tab (T-077 replaced it with Search).
 */
export default function MySharesScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: shares, isLoading } = useShares(25);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={safeBack} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.headerTitle}>{t('profile.myShares')}</Text>
        <View style={styles.headerSpacer} />
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {isLoading ? (
          <View style={styles.center}>
            <ActivityIndicator color={c.primary} />
          </View>
        ) : !shares || shares.length === 0 ? (
          <Text style={styles.empty}>{t('shares.list.empty')}</Text>
        ) : (
          shares.map((s) => <Row key={s.id} share={s} styles={styles} c={c} t={t} />)
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function Row({
  share,
  styles,
  c,
  t,
}: {
  share: ShareDetail;
  styles: Styles;
  c: Palette;
  t: (k: MessageKey) => string;
}) {
  const label =
    share.place?.name ?? share.source_post.caption ?? share.source_post.url?.replace(/^https?:\/\//, '') ?? '—';
  const tone =
    share.status === 'published'
      ? { bg: c.greenSoft, fg: c.green }
      : share.status === 'review'
        ? { bg: c.goldSoft, fg: c.gold }
        : share.status === 'failed' || share.status === 'rejected'
          ? { bg: c.dangerSoft, fg: c.danger }
          : { bg: c.primarySoft, fg: c.primary };
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
      <Text style={styles.rowLabel} numberOfLines={1}>
        {label}
      </Text>
      <View style={[styles.pill, { backgroundColor: tone.bg }]}>
        <Text style={[styles.pillText, { color: tone.fg }]}>{t(STATUS_KEY[share.status])}</Text>
      </View>
      <Ionicons name="chevron-forward" size={16} color={c.muted} />
    </Pressable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      paddingHorizontal: 16,
      paddingVertical: 10,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    headerTitle: { flex: 1, fontFamily: fonts.display, fontSize: 20, fontWeight: '700', color: c.text },
    headerSpacer: { width: 26 },
    scroll: { padding: 16 },
    center: { paddingVertical: 48, alignItems: 'center' },
    empty: { fontSize: 15, color: c.muted, textAlign: 'center', paddingVertical: 48 },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingVertical: 14,
      paddingHorizontal: 8,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    rowLabel: { flex: 1, fontSize: 15, color: c.text },
    pressed: { opacity: 0.6 },
    pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
    pillText: { fontSize: 12, fontWeight: '700' },
  });
