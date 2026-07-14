import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useFollow, useProfile } from '@/api/hooks/useProfile';
import { Button } from '@/components/button';
import { FeedCard } from '@/components/feed/feed-card';
import { useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

export default function UserProfileScreen() {
  const { username } = useLocalSearchParams<{ username: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data, isLoading, isError } = useProfile(username ?? null);
  const me = useSessionStore((s) => s.user);
  const authed = useSessionStore((s) => s.status === 'authed');
  const { follow, unfollow } = useFollow();

  const profile = data?.profile;
  const viewer = data?.viewer;
  const isSelf = !!(me && profile && me.username === profile.username);
  const busy = follow.isPending || unfollow.isPending;

  const onToggleFollow = () => {
    if (!profile || !viewer || busy) return;
    if (viewer.following && viewer.follow_id) {
      unfollow.mutate({ username: profile.username, followId: viewer.follow_id });
    } else {
      follow.mutate({ username: profile.username, userId: profile.id });
    }
  };

  const initial = (profile?.name ?? profile?.username ?? '?').charAt(0).toUpperCase();

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('place.back')}
          onPress={() => (router.canGoBack() ? router.back() : router.replace('/(main)/map'))}
          hitSlop={12}
        >
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.headerTitle} numberOfLines={1}>
          {profile ? `@${profile.username}` : ''}
        </Text>
        <View style={styles.spacer} />
      </View>

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : isError || !profile ? (
        <View style={styles.empty}>
          <Ionicons name="person-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('profileUser.notFound')}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          <View style={styles.top}>
            <View style={styles.avatar}>
              <Text style={styles.avatarText}>{initial}</Text>
            </View>
            <Text style={styles.name}>{profile.name ?? `@${profile.username}`}</Text>
            <Text style={styles.username}>@{profile.username}</Text>
            {profile.bio ? <Text style={styles.bio}>{profile.bio}</Text> : null}
          </View>

          <View style={styles.counters}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`${profile.counters.followers} ${t('profileUser.followers')}`}
              onPress={() => router.push({ pathname: '/users/[username]/followers', params: { username: profile.username } })}
              style={styles.counter}
            >
              <Text style={styles.counterValue}>{profile.counters.followers}</Text>
              <Text style={styles.counterLabel}>{t('profileUser.followers')}</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`${profile.counters.following} ${t('profileUser.following')}`}
              onPress={() => router.push({ pathname: '/users/[username]/following', params: { username: profile.username } })}
              style={styles.counter}
            >
              <Text style={styles.counterValue}>{profile.counters.following}</Text>
              <Text style={styles.counterLabel}>{t('profileUser.following')}</Text>
            </Pressable>
            <View style={styles.counter}>
              <Text style={styles.counterValue}>{profile.counters.published_shares}</Text>
              <Text style={styles.counterLabel}>{t('profileUser.shares')}</Text>
            </View>
          </View>

          {authed && !isSelf && viewer ? (
            <Button
              title={viewer.following ? t('follow.following') : t('follow.follow')}
              accessibilityLabel={viewer.following ? t('follow.following') : t('follow.follow')}
              variant={viewer.following ? 'secondary' : 'primary'}
              onPress={onToggleFollow}
              loading={busy}
            />
          ) : null}

          <View style={styles.shares}>
            {data.shares.length === 0 ? (
              <Text style={styles.emptyText}>{t('profileUser.noShares')}</Text>
            ) : (
              data.shares.map((item) => (
                <FeedCard
                  key={item.id}
                  item={item}
                  onPress={(slug) => router.push({ pathname: '/place/[slug]', params: { slug } })}
                />
              ))
            )}
          </View>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 12,
      paddingHorizontal: 16,
      paddingVertical: 12,
    },
    headerTitle: { flex: 1, fontSize: 18, fontWeight: '700', color: c.text },
    spacer: { width: 26 },
    loading: { paddingVertical: 40 },
    empty: { alignItems: 'center', gap: 10, paddingTop: 80, paddingHorizontal: 40 },
    emptyText: { fontSize: 15, color: c.muted, textAlign: 'center' },
    scroll: { padding: 20, gap: 16 },
    top: { alignItems: 'center', gap: 4 },
    avatar: {
      width: 80,
      height: 80,
      borderRadius: 40,
      backgroundColor: c.primarySoft,
      alignItems: 'center',
      justifyContent: 'center',
      marginBottom: 6,
    },
    avatarText: { fontSize: 32, fontWeight: '700', color: c.primary },
    name: { fontSize: 24, fontWeight: '700', color: c.text },
    username: { fontSize: 15, color: c.muted },
    bio: { fontSize: 14, color: c.ink2, textAlign: 'center', marginTop: 4 },
    counters: { flexDirection: 'row', justifyContent: 'space-around', paddingVertical: 8 },
    counter: { alignItems: 'center', gap: 2 },
    counterValue: { fontSize: 18, fontWeight: '700', color: c.text },
    counterLabel: { fontSize: 12, color: c.muted },
    shares: { gap: 4, marginTop: 8 },
  });
