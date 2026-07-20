import { Link, router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { useLogin } from '@/api/hooks/useAuth';
import { EmailNotVerifiedError } from '@/api/types';
import { AuthScreenLayout, useAuthFormStyles } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';
import { useUiStore } from '@/stores/ui';

export default function LoginScreen() {
  const styles = useAuthFormStyles();
  const t = useT();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const login = useLogin();
  // A share staged by the root ShareIntentRedirect while logged out (T-025):
  // show a banner and, once signed in, resume on the ingest screen instead of
  // dropping to the map — the shared link is never lost to the auth gate.
  const pendingShare = useUiStore((s) => s.pendingShare);

  const { fieldErrors, generalError } = formErrors(login.error);

  function submit() {
    login.mutate(
      { email: email.trim(), password },
      {
        onSuccess: () => router.replace(pendingShare ? '/(main)/share' : '/(main)/map'),
        onError: (err) => {
          setPassword('');
          // Unconfirmed account → send them to the verify flow prefilled. Reset
          // first so the (non-field) error doesn't linger on the login form if
          // they swipe back.
          if (err instanceof EmailNotVerifiedError) {
            login.reset();
            router.push({ pathname: '/(auth)/verify-email', params: { email: err.email || email.trim() } });
          }
        },
      },
    );
  }

  return (
    <AuthScreenLayout title={t('auth.login.title')} subtitle={t('auth.login.subtitle')}>
      {pendingShare ? (
        <View style={styles.banner} accessibilityRole="alert">
          <Text style={styles.bannerText}>{t('auth.login.shareBanner')}</Text>
        </View>
      ) : null}
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
