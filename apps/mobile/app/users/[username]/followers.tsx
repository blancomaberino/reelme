import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useFollowers } from '@/api/hooks/useProfile';
import { FollowList, type FollowListRow } from '@/components/profile/follow-list';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

export default function FollowersScreen() {
  const { username } = useLocalSearchParams<{ username: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data, isLoading, isError } = useFollowers(username ?? null);

  const rows: FollowListRow[] | undefined = useMemo(
    () =>
      data?.map((r) => ({
        id: r.id,
        title: r.user?.name ?? (r.user ? `@${r.user.username}` : t('profileUser.privateUser')),
        handle: r.user ? `@${r.user.username}` : '',
        username: r.user?.username ?? null,
      })),
    [data, t],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={() => router.back()} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title}>{t('profileUser.followers')}</Text>
        <View style={styles.spacer} />
      </View>
      <FollowList rows={rows} isLoading={isLoading} isError={isError} emptyText={t('profileUser.noFollowers')} />
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
