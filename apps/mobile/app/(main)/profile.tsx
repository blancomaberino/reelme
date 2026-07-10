import { router } from 'expo-router';
import { SafeAreaView, StyleSheet, Text, View } from 'react-native';

import { useLogout } from '@/api/hooks/useAuth';
import { Button } from '@/components/button';
import { useSessionStore } from '@/stores/session';
import { colors } from '@/theme/colors';

export default function ProfileScreen() {
  const user = useSessionStore((s) => s.user);
  const logout = useLogout();

  function onLogout() {
    logout.mutate(undefined, { onSuccess: () => router.replace('/(auth)/welcome') });
  }

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.header}>
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

const styles = StyleSheet.create({
  safe: { flex: 1, padding: 24, justifyContent: 'space-between' },
  header: { marginTop: 24, gap: 4 },
  name: { fontSize: 28, fontWeight: '700' },
  username: { fontSize: 16, color: colors.muted },
  note: { flex: 1, marginTop: 24, color: colors.muted },
  footer: { gap: 12 },
});
