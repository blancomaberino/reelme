import type { ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, SafeAreaView, ScrollView, StyleSheet, Text } from 'react-native';

import { colors } from '@/theme/colors';

/** Shared scaffold for the login/register forms: safe area + keyboard avoidance + title. */
export function AuthScreenLayout({ title, children }: { title: string; children: ReactNode }) {
  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.title}>{title}</Text>
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  content: { padding: 24, gap: 16, flexGrow: 1, justifyContent: 'center' },
  title: { fontSize: 28, fontWeight: '700', marginBottom: 8, color: colors.text },
});
