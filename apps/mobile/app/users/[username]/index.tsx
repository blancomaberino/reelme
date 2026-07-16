import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useFollow, useProfile, useUserLists, useUserPlaces } from '@/api/hooks/useProfile';
import { Button } from '@/components/button';
import { MyPlaceCard } from '@/components/place/my-place-card';
import { useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

type Tab = 'places' | 'lists';

export default function UserProfileScreen() {
  const { username } = useLocalSearchParams<{ username: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data, isLoading, isError } = useProfile(username ?? null);
  const me = useSessionStore((s) => s.user);
  const authed = useSessionStore((s) => s.status === 'authed');
  const { follow, unfollow } = useFollow();
  const [tab, setTab] = useState<Tab>('places');
  const { data: places } = useUserPlaces(username ?? null);
  const { data: lists } = useUserLists(username ?? null);

  const openPlace = (slug: string) => router.push({ pathname: '/place/[slug]', params: { slug } });

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

          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('profileUser.viewMap')}
            onPress={() => router.push({ pathname: '/users/[username]/map', params: { username: profile.username } })}
            style={styles.mapButton}
          >
            <Ionicons name="map-outline" size={18} color={c.primary} />
            <Text style={styles.mapButtonText}>{t('profileUser.viewMap')}</Text>
          </Pressable>

          <View style={styles.segment}>
            <SegmentTab label={t('profileUser.places')} active={tab === 'places'} onPress={() => setTab('places')} styles={styles} />
            <SegmentTab label={t('profileUser.lists')} active={tab === 'lists'} onPress={() => setTab('lists')} styles={styles} />
          </View>

          {tab === 'places' ? (
            <View style={styles.content}>
              {(places?.length ?? 0) === 0 ? (
                <Text style={styles.emptyText}>{t('profileUser.noPlaces')}</Text>
              ) : (
                places?.map((place) => <MyPlaceCard key={place.id} place={place} onPress={openPlace} />)
              )}
            </View>
          ) : (
            <View style={styles.content}>
              {(lists?.length ?? 0) === 0 ? (
                <Text style={styles.emptyText}>{t('profileUser.noLists')}</Text>
              ) : (
                lists?.map((list) => (
                  <Pressable
                    key={list.id}
                    accessibilityRole="button"
                    accessibilityLabel={list.name}
                    onPress={() => list.public_slug && router.push({ pathname: '/list/[slug]', params: { slug: list.public_slug } })}
                    style={({ pressed }) => [styles.listRow, pressed && styles.listRowPressed]}
                  >
                    <Ionicons name="bookmark-outline" size={20} color={c.primary} />
                    <View style={styles.listRowBody}>
                      <Text style={styles.listRowName} numberOfLines={1}>
                        {list.name}
                      </Text>
                      <Text style={styles.listRowCount}>{t('profileUser.listCount', { count: list.items_count })}</Text>
                    </View>
                    <Ionicons name="chevron-forward" size={18} color={c.muted} />
                  </Pressable>
                ))
              )}
            </View>
          )}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function SegmentTab({
  label,
  active,
  onPress,
  styles,
}: {
  label: string;
  active: boolean;
  onPress: () => void;
  styles: ReturnType<typeof makeStyles>;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected: active }}
      accessibilityLabel={label}
      onPress={onPress}
      style={[styles.segmentTab, active && styles.segmentTabActive]}
    >
      <Text style={[styles.segmentText, active && styles.segmentTextActive]}>{label}</Text>
    </Pressable>
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
    mapButton: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 8,
      paddingVertical: 12,
      borderRadius: 12,
      borderWidth: 1.5,
      borderColor: c.primary,
    },
    mapButtonText: { color: c.primary, fontSize: 15, fontWeight: '700' },
    segment: {
      flexDirection: 'row',
      gap: 6,
      backgroundColor: c.surface,
      borderRadius: 999,
      padding: 4,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    segmentTab: { flex: 1, alignItems: 'center', paddingVertical: 8, borderRadius: 999 },
    segmentTabActive: { backgroundColor: c.primary },
    segmentText: { fontSize: 14, fontWeight: '700', color: c.muted },
    segmentTextActive: { color: c.onPrimary },
    content: { gap: 12, marginTop: 4 },
    listRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      padding: 14,
      backgroundColor: c.surface,
      borderRadius: 14,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    listRowPressed: { opacity: 0.7 },
    listRowBody: { flex: 1, gap: 2 },
    listRowName: { fontSize: 16, fontWeight: '700', color: c.text },
    listRowCount: { fontSize: 13, color: c.muted },
  });
