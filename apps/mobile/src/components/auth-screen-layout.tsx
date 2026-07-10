import { type ReactNode, useMemo } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { type Palette, useColors } from '@/theme/colors';

/** Shared scaffold for the login/register forms: safe area + keyboard avoidance + heading. */
export function AuthScreenLayout({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
}) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <View style={styles.heading}>
            <Text style={styles.title}>{title}</Text>
            {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
          </View>
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    flex: { flex: 1 },
    content: { padding: 24, gap: 16, flexGrow: 1, justifyContent: 'center' },
    heading: { gap: 6, marginBottom: 8 },
    title: { fontSize: 30, fontWeight: '700', letterSpacing: -0.4, color: c.text },
    subtitle: { fontSize: 15, lineHeight: 22, color: c.muted },
  });

/** Shared error/footer styling for the login and register forms. */
export function useAuthFormStyles() {
  const c = useColors();
  return useMemo(
    () =>
      StyleSheet.create({
        general: { color: c.danger, fontSize: 14 },
        footer: { flexDirection: 'row', justifyContent: 'center', marginTop: 8 },
        muted: { color: c.muted },
        link: { color: c.primary, fontWeight: '600' },
      }),
    [c],
  );
}
