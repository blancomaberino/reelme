import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useFollowInfluencer, useInfluencer, useInfluencerPlaces } from '@/api/hooks/useInfluencer';
import { Button } from '@/components/button';
import { MyPlaceCard } from '@/components/place/my-place-card';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * An influencer's public profile (T-036/T-039) — the creator side of the
 * network, distinct from a user profile: an influencer is an *identity on a
 * platform* that may not correspond to any Reelmap account at all.
 *
 * Which is why the claim CTA is here. An unclaimed identity is the normal
 * state (we mint one from every shared post's author), and the person behind
 * it discovering their own page is the intended route into claiming it.
 */
export default function InfluencerProfileScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data, isLoading, isError } = useInfluencer(id ?? null);
  const { data: places, isError: placesFailed } = useInfluencerPlaces(id ?? null);
  const authed = useSessionStore((s) => s.status === 'authed');
  const { follow, unfollow } = useFollowInfluencer();

  const profile = data?.profile;
  const viewer = data?.viewer;
  const busy = follow.isPending || unfollow.isPending;

  const onToggleFollow = () => {
    if (!profile || !viewer || busy) return;
    if (viewer.following && viewer.follow_id) {
      unfollow.mutate({ id: profile.id, followId: viewer.follow_id });
    } else {
      follow.mutate({ id: profile.id });
    }
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={profile ? `@${profile.handle}` : ''} />

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : isError || !profile ? (
        <View style={styles.empty} testID="influencer-not-found">
          <Ionicons name="person-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('influencer.notFound')}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          <View style={styles.top}>
            <View style={styles.avatar}>
              <Text style={styles.avatarText}>{(profile.display_name ?? profile.handle).charAt(0).toUpperCase()}</Text>
            </View>
            <Text style={styles.name}>{profile.display_name ?? `@${profile.handle}`}</Text>
            <View style={styles.handleRow}>
              <Text style={styles.username}>@{profile.handle}</Text>
              <Text style={styles.platform}>{profile.platform}</Text>
            </View>
            {profile.claimed ? (
              <View style={styles.claimedBadge}>
                <Ionicons name="checkmark-circle" size={14} color={c.green} />
                <Text style={styles.claimedText}>
                  {profile.claimed_by
                    ? t('influencer.claimedBy', { username: profile.claimed_by })
                    : t('influencer.claimed')}
                </Text>
              </View>
            ) : null}
          </View>

          <View style={styles.counters}>
            <View style={styles.counter}>
              <Text style={styles.counterValue}>{profile.counters.promoted_places}</Text>
              <Text style={styles.counterLabel}>{t('influencer.places')}</Text>
            </View>
            <View style={styles.counter}>
              <Text style={styles.counterValue}>{profile.follower_count}</Text>
              <Text style={styles.counterLabel}>{t('influencer.followers')}</Text>
            </View>
          </View>

          {authed && viewer ? (
            <Button
              title={viewer.following ? t('follow.following') : t('follow.follow')}
              accessibilityLabel={viewer.following ? t('follow.following') : t('follow.follow')}
              variant={viewer.following ? 'secondary' : 'primary'}
              onPress={onToggleFollow}
              loading={busy}
            />
          ) : null}

          <Button
            title={t('influencer.viewMap')}
            variant="ghost"
            icon="map-outline"
            onPress={() => router.push({ pathname: '/influencer/[id]/map', params: { id: profile.id } })}
          />

          {/* Only offered to a signed-in viewer on an UNCLAIMED identity — a
              claimed one is settled, and a guest has no account to attach. */}
          {authed && !profile.claimed ? (
            <View style={styles.claimCta}>
              <Text style={styles.claimTitle}>{t('influencer.claim.prompt')}</Text>
              <Text style={styles.claimBody}>{t('influencer.claim.promptBody')}</Text>
              <Button
                title={t('influencer.claim.start')}
                onPress={() => router.push({ pathname: '/influencer/[id]/claim', params: { id: profile.id } })}
              />
            </View>
          ) : null}

          {/* Their places, listed — the sibling of the user profile's list.
              The counter above said "2 Lugares" while the screen showed none of
              them and the map screen showed none either; a number with nothing
              behind it is a claim the user cannot check.

              A FAILED load is not an empty one. Hiding the section on error is
              the same mistake the map screen made — silently answering a
              question with data nobody has — and I reproduced it here in the
              very commit that fixed it there. The counter above stays visible
              either way, so saying nothing is what makes the two disagree. */}
          {placesFailed ? (
            <View style={styles.places}>
              <Text style={styles.sectionTitle}>{t('influencer.places')}</Text>
              <Text style={styles.placesError} testID="influencer-places-error">
                {t('common.error.general')}
              </Text>
            </View>
          ) : places && places.length > 0 ? (
            <View style={styles.places}>
              <Text style={styles.sectionTitle}>{t('influencer.places')}</Text>
              {places.map((place) => (
                <MyPlaceCard
                  key={place.id}
                  place={place}
                  onPress={(slug) => router.push({ pathname: '/place/[slug]', params: { slug } })}
                />
              ))}
            </View>
          ) : null}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { paddingVertical: space.xl },
    scroll: { padding: space.md, gap: space.md },
    places: { gap: space.xs, marginTop: space.xs },
    sectionTitle: { ...type.title, color: c.text },
    placesError: { ...type.body, color: c.muted },
    top: { alignItems: 'center', gap: space.xxs },
    avatar: {
      width: 72,
      height: 72,
      borderRadius: radius.pill,
      backgroundColor: c.primarySoft,
      alignItems: 'center',
      justifyContent: 'center',
      marginBottom: space.xs,
    },
    avatarText: { ...type.hero, color: c.primary },
    name: { ...type.display, color: c.text, textAlign: 'center' },
    handleRow: { flexDirection: 'row', alignItems: 'center', gap: space.xs },
    username: { ...type.body, color: c.muted },
    platform: {
      ...type.caption,
      color: c.secondary,
      backgroundColor: c.secondarySoft,
      paddingHorizontal: space.xs,
      paddingVertical: space.xxs,
      borderRadius: radius.pill,
      overflow: 'hidden',
      textTransform: 'capitalize',
    },
    claimedBadge: { flexDirection: 'row', alignItems: 'center', gap: space.xxs, marginTop: space.xxs },
    claimedText: { ...type.bodySm, color: c.green },
    counters: { flexDirection: 'row', justifyContent: 'center', gap: space.xl },
    counter: { alignItems: 'center' },
    counterValue: { ...type.title, color: c.text },
    counterLabel: { ...type.bodySm, color: c.muted },
    claimCta: {
      gap: space.xs,
      padding: space.md,
      borderRadius: radius.md,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    claimTitle: { ...type.bodyLg, color: c.text },
    claimBody: { ...type.bodySm, color: c.muted },
    empty: { alignItems: 'center', gap: space.xs, paddingTop: space.xxl, paddingHorizontal: space.xl },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },
  });
