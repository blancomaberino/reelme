import { Link, router } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, SafeAreaView, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useRegister } from '@/api/hooks/useAuth';
import { ValidationError } from '@/api/types';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';

export default function RegisterScreen() {
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const register = useRegister();

  const fieldErrors = register.error instanceof ValidationError ? register.error.fields : {};
  const generalError =
    register.error && !(register.error instanceof ValidationError) ? 'Something went wrong. Please try again.' : null;

  function submit() {
    register.mutate(
      { name: name.trim(), username: username.trim(), email: email.trim(), password },
      { onSuccess: () => router.replace('/(main)/map') },
    );
  }

  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.title}>Create your account</Text>
          <TextField label="Name" value={name} onChangeText={setName} autoCapitalize="words" error={fieldErrors.name} />
          <TextField label="Username" value={username} onChangeText={setUsername} error={fieldErrors.username} />
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
          <Button title="Create account" onPress={submit} loading={register.isPending} />
          <View style={styles.footer}>
            <Text style={styles.muted}>Already have an account? </Text>
            <Link href="/(auth)/login" style={styles.link}>
              Log in
            </Link>
          </View>
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
