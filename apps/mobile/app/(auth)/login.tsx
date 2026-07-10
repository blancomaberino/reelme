import { Link, router } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { useLogin } from '@/api/hooks/useAuth';
import { AuthScreenLayout } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { formErrors } from '@/lib/form-errors';
import { colors } from '@/theme/colors';

export default function LoginScreen() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const login = useLogin();

  const { fieldErrors, generalError } = formErrors(login.error);

  function submit() {
    login.mutate(
      { email: email.trim(), password },
      {
        onSuccess: () => router.replace('/(main)/map'),
        onError: () => setPassword(''),
      },
    );
  }

  return (
    <AuthScreenLayout title="Welcome back">
      <TextField
        label="Email"
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoComplete="email"
        textContentType="emailAddress"
        error={fieldErrors.email}
      />
      <TextField
        label="Password"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="current-password"
        textContentType="password"
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
    </AuthScreenLayout>
  );
}

const styles = StyleSheet.create({
  general: { color: colors.danger, fontSize: 14 },
  footer: { flexDirection: 'row', justifyContent: 'center', marginTop: 8 },
  muted: { color: colors.muted },
  link: { color: colors.primary, fontWeight: '600' },
});
