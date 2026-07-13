import { Link, router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { useLogin } from '@/api/hooks/useAuth';
import { AuthScreenLayout, useAuthFormStyles } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';

export default function LoginScreen() {
  const styles = useAuthFormStyles();
  const t = useT();
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
    <AuthScreenLayout title={t('auth.login.title')} subtitle={t('auth.login.subtitle')}>
      <TextField
        label={t('auth.field.email')}
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoComplete="email"
        textContentType="emailAddress"
        error={fieldErrors.email}
      />
      <TextField
        label={t('auth.field.password')}
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="current-password"
        textContentType="password"
        error={fieldErrors.password}
      />
      {generalError ? <Text style={styles.general}>{generalError}</Text> : null}
      <Button title={t('auth.login.submit')} onPress={submit} loading={login.isPending} />
      <View style={styles.footer}>
        <Text style={styles.muted}>{t('auth.login.newHere')}</Text>
        <Link href="/(auth)/register" style={styles.link}>
          {t('auth.login.createAccount')}
        </Link>
      </View>
      {/* Social sign-in (Apple/Google) ships once POST /auth/social is implemented. */}
    </AuthScreenLayout>
  );
}
