import { Link } from 'expo-router';
import { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button } from '@/components/button';
import { type Palette, useColors } from '@/theme/colors';

export default function WelcomeScreen() {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.hero}>
        <View style={styles.logoMark}>
          <Text style={styles.logoGlyph}>📍</Text>
        </View>
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
        <Text style={styles.legal}>By continuing you agree to our Terms & Privacy Policy.</Text>
      </View>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, padding: 24, justifyContent: 'space-between', backgroundColor: c.background },
    hero: { flex: 1, justifyContent: 'center', gap: 16 },
    logoMark: {
      width: 72,
      height: 72,
      borderRadius: 20,
      backgroundColor: c.primarySoft,
      alignItems: 'center',
      justifyContent: 'center',
    },
    logoGlyph: { fontSize: 38 },
    logo: { fontSize: 44, fontWeight: '800', letterSpacing: -1, color: c.primary },
    tagline: { fontSize: 18, lineHeight: 26, color: c.text },
    actions: { gap: 12 },
    legal: { fontSize: 12, lineHeight: 18, textAlign: 'center', color: c.muted, marginTop: 4 },
  });
