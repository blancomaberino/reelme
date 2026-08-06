import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useLogout } from '@/api/hooks/useAuth';
import { useProfile } from '@/api/hooks/useProfile';
import { Button } from '@/components/button';
import { ProfileCounters } from '@/components/profile/profile-counters';
import { VerifyEmailBanner } from '@/components/verify-email-banner';
import { useT } from '@/i18n';
import { useUnreadCount } from '@/api/hooks/useNotifications';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

export default function ProfileScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const user = useSessionStore((s) => s.user);
  const authed = useSessionStore((s) => s.status === 'authed');
  const unread = useUnreadCount();
  const logout = useLogout();

  /*
   * The social counters come from the PUBLIC profile endpoint, not `/me`.
   *
   * 05 §3 sketched them as new fields on `GET /me`, but `UserResource` is
   * returned from six places (login, refresh, verify-email, analysis
   * preference, me, me/update) and a count that isn't eager-loaded at each of
   * them renders a confident `0` — a silently wrong number is worse than a
   * missing one. `GET /users/{username}` already computes exactly these three,
   * and a private profile 404s for everyone EXCEPT its owner, so this works
   * whether or not the account is public. Same numbers others see, one code
   * path, no new API surface. (Deviation recorded on T-039 in the plan.)
   */
  const { data: publicProfile } = useProfile(user?.username ?? null);

  function onLogout() {
    logout.mutate(undefined, { onSuccess: () => router.replace('/(auth)/welcome') });
  }

  const initial = (user?.name ?? user?.username ?? '?').charAt(0).toUpperCase();

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.header}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>{initial}</Text>
        </View>
        <Text style={styles.name}>{user?.name ?? t('profile.title')}</Text>
        {user ? <Text style={styles.username}>@{user.username}</Text> : null}
        {authed ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={
              unread > 0 ? `${t('notifications.title')} (${unread})` : t('notifications.title')
            }
            onPress={() => router.push('/notifications')}
            hitSlop={12}
            style={styles.bell}
            testID="notifications-bell"
          >
            <Ionicons name="notifications-outline" size={24} color={c.text} />
            {unread > 0 ? (
              <View style={styles.badge} testID="notifications-badge">
                {/* Capped: a three-digit badge stops being a number and starts
                    being noise, and it would blow the pill's width. */}
                <Text style={styles.badgeText}>{unread > 99 ? '99+' : unread}</Text>
              </View>
            ) : null}
          </Pressable>
        ) : null}
      </View>
      {/* Rendered only once the numbers are in: a counter row that flashes 0 → 12
          reads as "you lost your followers" for the frame it is wrong. */}
      {user && publicProfile?.profile ? (
        <ProfileCounters username={user.username} counters={publicProfile.profile.counters} />
      ) : null}

      <View style={styles.body}>
        <VerifyEmailBanner />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('profile.myShares')}
          onPress={() => router.push('/shares')}
          style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
        >
          <Ionicons name="share-outline" size={20} color={c.text} />
          <Text style={styles.settingsLabel}>{t('profile.myShares')}</Text>
          <Ionicons name="chevron-forward" size={18} color={c.muted} />
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('profile.edit')}
          onPress={() => router.push('/profile/edit')}
          style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
        >
          <Ionicons name="person-outline" size={20} color={c.text} />
          <Text style={styles.settingsLabel}>{t('profile.edit')}</Text>
          <Ionicons name="chevron-forward" size={18} color={c.muted} />
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('profile.lists')}
          onPress={() => router.push('/lists')}
          style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
        >
          <Ionicons name="bookmark-outline" size={20} color={c.text} />
          <Text style={styles.settingsLabel}>{t('profile.lists')}</Text>
          <Ionicons name="chevron-forward" size={18} color={c.muted} />
        </Pressable>
        {/* The restaurant surface (T-042) appears only once a place claim has
            been verified — `is_restaurant_owner` is set by that approval. A
            diner has nothing to manage, so the row would be a dead end. */}
        {user?.is_restaurant_owner ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('profile.restaurant')}
            onPress={() => router.push('/restaurant/offers')}
            style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
            testID="profile-restaurant"
          >
            <Ionicons name="pricetag-outline" size={20} color={c.text} />
            <Text style={styles.settingsLabel}>{t('profile.restaurant')}</Text>
            <Ionicons name="chevron-forward" size={18} color={c.muted} />
          </Pressable>
        ) : null}
        {/* The till (T-047). Same gate as the offers screen — an operator needs
            this in reach during service, not buried under offer management. */}
        {user?.is_restaurant_owner ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('profile.verifyCode')}
            onPress={() => router.push('/restaurant/verify')}
            style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
            testID="profile-verify"
          >
            <Ionicons name="qr-code-outline" size={20} color={c.text} />
            <Text style={styles.settingsLabel}>{t('profile.verifyCode')}</Text>
            <Ionicons name="chevron-forward" size={18} color={c.muted} />
          </Pressable>
        ) : null}
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('profile.invite')}
          onPress={() => router.push('/invite')}
          style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
        >
          <Ionicons name="person-add-outline" size={20} color={c.text} />
          <Text style={styles.settingsLabel}>{t('profile.invite')}</Text>
          <Ionicons name="chevron-forward" size={18} color={c.muted} />
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('profile.settings')}
          onPress={() => router.push('/settings')}
          style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
        >
          <Ionicons name="settings-outline" size={20} color={c.text} />
          <Text style={styles.settingsLabel}>{t('profile.settings')}</Text>
          <Ionicons name="chevron-forward" size={18} color={c.muted} />
        </Pressable>
      </View>
      <View style={styles.footer}>
        <Button title={t('profile.logout')} variant="secondary" onPress={onLogout} loading={logout.isPending} />
      </View>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, padding: 24, justifyContent: 'space-between', backgroundColor: c.background },
    header: { marginTop: 24, gap: 6, alignItems: 'center' },
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
    name: { fontSize: 28, fontWeight: '700', color: c.text },
    bell: { position: 'absolute', right: 0, top: 0, padding: 4 },
    badge: {
      position: 'absolute',
      right: 0,
      top: 0,
      minWidth: 18,
      height: 18,
      paddingHorizontal: 4,
      borderRadius: 9,
      backgroundColor: c.primary,
      alignItems: 'center',
      justifyContent: 'center',
    },
    badgeText: { color: c.onPrimary, fontSize: 11, fontWeight: '800' },
    username: { fontSize: 16, color: c.muted },
    body: { flex: 1, marginTop: 24 },
    settingsRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      paddingVertical: 14,
      paddingHorizontal: 4,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    settingsLabel: { flex: 1, fontSize: 16, color: c.text, fontWeight: '600' },
    pressed: { opacity: 0.6 },
    footer: { gap: 12 },
  });
