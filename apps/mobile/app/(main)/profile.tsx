import { router } from 'expo-router';
import { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useLogout } from '@/api/hooks/useAuth';
import { Button } from '@/components/button';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

export default function ProfileScreen() {
  const c = useColors();
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
        <Text style={styles.name}>{user?.name ?? 'Profile'}</Text>
        {user ? <Text style={styles.username}>@{user.username}</Text> : null}
      </View>
      <Text style={styles.note}>Your shares, followers & settings land here (T-039).</Text>
      <View style={styles.footer}>
        <Button title="Log out" variant="secondary" onPress={onLogout} loading={logout.isPending} />
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
    note: { flex: 1, marginTop: 24, textAlign: 'center', color: c.muted },
    footer: { gap: 12 },
  });
