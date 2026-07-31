import { Stack, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useFollowers } from '@/api/hooks/useProfile';
import { FollowList, type FollowListRow } from '@/components/profile/follow-list';
import { ScreenHeader } from '@/components/screen-header';
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
      <ScreenHeader title={t('profileUser.followers')} />
      <FollowList rows={rows} isLoading={isLoading} isError={isError} emptyText={t('profileUser.noFollowers')} />
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
  });
