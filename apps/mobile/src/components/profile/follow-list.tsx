import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

/** A normalized row: a followable person. `username` null → not tappable (e.g.
 *  an influencer, or a user who has since gone private). */
export type FollowListRow = { id: string; title: string; handle: string; username: string | null };

type Props = {
  rows: FollowListRow[] | undefined;
  isLoading: boolean;
  isError: boolean;
  emptyText: string;
};

/** Shared list body for the followers / following screens (T-039). */
export function FollowList({ rows, isLoading, isError, emptyText }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  if (isLoading) {
    return <ActivityIndicator color={c.primary} style={styles.loading} />;
  }
  if (isError) {
    return <Text style={styles.empty}>{t('common.tryAgain')}</Text>;
  }
  if (!rows || rows.length === 0) {
    return <Text style={styles.empty}>{emptyText}</Text>;
  }

  return (
    <ScrollView contentContainerStyle={styles.scroll}>
      {rows.map((row) => {
        const initial = (row.title || row.handle || '?').charAt(0).toUpperCase();
        const go = row.username
          ? () => router.push({ pathname: '/users/[username]', params: { username: row.username as string } })
          : undefined;
        return (
          <Pressable
            key={row.id}
            accessibilityRole={go ? 'button' : 'text'}
            accessibilityLabel={row.handle}
            onPress={go}
            disabled={!go}
            style={({ pressed }) => [styles.row, pressed && go ? styles.pressed : null]}
          >
            <View style={styles.avatar}>
              <Text style={styles.avatarText}>{initial}</Text>
            </View>
            <View style={styles.body}>
              <Text style={styles.title} numberOfLines={1}>
                {row.title}
              </Text>
              <Text style={styles.handle} numberOfLines={1}>
                {row.handle}
              </Text>
            </View>
            {go ? <Ionicons name="chevron-forward" size={18} color={c.muted} /> : null}
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    loading: { paddingVertical: 40 },
    empty: { paddingVertical: 60, textAlign: 'center', color: c.muted, fontSize: 15 },
    scroll: { padding: 16, gap: 4 },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      paddingVertical: 10,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    pressed: { opacity: 0.6 },
    avatar: {
      width: 44,
      height: 44,
      borderRadius: 22,
      backgroundColor: c.primarySoft,
      alignItems: 'center',
      justifyContent: 'center',
    },
    avatarText: { fontSize: 18, fontWeight: '700', color: c.primary },
    body: { flex: 1 },
    title: { fontFamily: fonts.display, fontSize: 15, fontWeight: '700', color: c.text },
    handle: { fontSize: 13, color: c.muted },
  });
