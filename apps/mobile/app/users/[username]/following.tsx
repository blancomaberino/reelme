import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useFollowing } from '@/api/hooks/useProfile';
import { FollowList, type FollowListRow } from '@/components/profile/follow-list';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

export default function FollowingScreen() {
  const { username } = useLocalSearchParams<{ username: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data, isLoading, isError } = useFollowing(username ?? null);

  const rows: FollowListRow[] | undefined = useMemo(
    () =>
      data?.map((r): FollowListRow => {
        const f = r.followee;
        // A withheld (private/stale) edge renders as a placeholder rather than
        // being dropped, so the list count matches the following counter (as the
        // followers list also does).
        if (!f) return { id: r.id, title: t('profileUser.privateUser'), handle: '', username: null };
        if (r.followable_type === 'influencer' && 'handle' in f) {
          return { id: r.id, title: f.display_name ?? `@${f.handle}`, handle: `@${f.handle}`, username: null };
        }
        if ('username' in f) {
          return { id: r.id, title: f.name ?? `@${f.username}`, handle: `@${f.username}`, username: f.username };
        }
        return { id: r.id, title: t('profileUser.privateUser'), handle: '', username: null };
      }),
    [data, t],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={() => router.back()} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title}>{t('profileUser.following')}</Text>
        <View style={styles.spacer} />
      </View>
      <FollowList rows={rows} isLoading={isLoading} isError={isError} emptyText={t('profileUser.noFollowing')} />
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 12 },
    title: { flex: 1, fontSize: 20, fontWeight: '700', color: c.text },
    spacer: { width: 26 },
  });
