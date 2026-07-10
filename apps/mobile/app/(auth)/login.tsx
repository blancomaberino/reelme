import { Link, router } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, SafeAreaView, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useLogin } from '@/api/hooks/useAuth';
import { ValidationError } from '@/api/types';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';

export default function LoginScreen() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const login = useLogin();

  const fieldErrors = login.error instanceof ValidationError ? login.error.fields : {};
  const generalError =
    login.error && !(login.error instanceof ValidationError) ? 'Something went wrong. Please try again.' : null;

  function submit() {
    login.mutate({ email: email.trim(), password }, { onSuccess: () => router.replace('/(main)/map') });
  }

  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.title}>Welcome back</Text>
          <TextField
            label="Email"
            value={email}
            onChangeText={setEmail}
            keyboardType="email-address"
            autoComplete="email"
            error={fieldErrors.email}
          />
          <TextField
            label="Password"
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            error={fieldErrors.password}
          />
          {generalError ? <Text style={styles.general}>{generalError}</Text> : null}
          <Button title="Log in" onPress={submit} loading={login.isPending} />
          <View style={styles.footer}>
            <Text style={styles.muted}>New here? </Text>
            <Link href="/(auth)/register" style={styles.link}>
              Create an account
            </Link>
          </View>
          {/* Social sign-in (Apple/Google) ships once POST /auth/social is implemented. */}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  flex: { flex: 1 },
  content: { padding: 24, gap: 16, flexGrow: 1, justifyContent: 'center' },
  title: { fontSize: 28, fontWeight: '700', marginBottom: 8 },
  general: { color: '#ef4444', fontSize: 14 },
  footer: { flexDirection: 'row', justifyContent: 'center', marginTop: 8 },
  muted: { color: '#6b7280' },
  link: { color: '#208AEF', fontWeight: '600' },
});
