import { Stack, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useFollowing } from '@/api/hooks/useProfile';
import { FollowList, type FollowListRow } from '@/components/profile/follow-list';
import { ScreenHeader } from '@/components/screen-header';
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
      <ScreenHeader title={t('profileUser.following')} />
      <FollowList rows={rows} isLoading={isLoading} isError={isError} emptyText={t('profileUser.noFollowing')} />
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
  });
