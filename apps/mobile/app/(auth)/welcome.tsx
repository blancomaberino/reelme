import { Link } from 'expo-router';
import { SafeAreaView, StyleSheet, Text, View } from 'react-native';

import { Button } from '@/components/button';
import { colors } from '@/theme/colors';

export default function WelcomeScreen() {
  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.hero}>
        <Text style={styles.logo}>Reelmap</Text>
        <Text style={styles.tagline}>Share a food video. Pin the place. Discover where the internet eats.</Text>
      </View>
      <View style={styles.actions}>
        <Link href="/(auth)/register" asChild>
          <Button title="Create account" />
        </Link>
        <Link href="/(auth)/login" asChild>
          <Button title="Log in" variant="secondary" />
        </Link>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, padding: 24, justifyContent: 'space-between' },
  hero: { flex: 1, justifyContent: 'center', gap: 16 },
  logo: { fontSize: 44, fontWeight: '800', color: colors.primary },
  tagline: { fontSize: 18, lineHeight: 26, color: colors.text },
  actions: { gap: 12 },
});
