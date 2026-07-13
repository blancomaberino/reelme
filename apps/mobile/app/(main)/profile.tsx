import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useLogout } from '@/api/hooks/useAuth';
import { Button } from '@/components/button';
import { useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

export default function ProfileScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const user = useSessionStore((s) => s.user);
  const logout = useLogout();

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
      </View>
      <View style={styles.body}>
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
          accessibilityLabel={t('profile.settings')}
          onPress={() => router.push('/settings')}
          style={({ pressed }) => [styles.settingsRow, pressed && styles.pressed]}
        >
          <Ionicons name="settings-outline" size={20} color={c.text} />
          <Text style={styles.settingsLabel}>{t('profile.settings')}</Text>
          <Ionicons name="chevron-forward" size={18} color={c.muted} />
        </Pressable>
        <Text style={styles.note}>{t('profile.note')}</Text>
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
    note: { marginTop: 24, textAlign: 'center', color: c.muted },
    footer: { gap: 12 },
  });
