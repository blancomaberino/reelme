import { Link, router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { useRegister } from '@/api/hooks/useAuth';
import { AgeRestrictedError } from '@/api/types';
import { AuthScreenLayout, useAuthFormStyles } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';
import { legalUrl } from '@/lib/legal';
import { openWebUrl } from '@/lib/linking';
import { useSettingsStore } from '@/stores/settings';
import { useUiStore } from '@/stores/ui';

export default function RegisterScreen() {
  const styles = useAuthFormStyles();
  const t = useT();
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [dateOfBirth, setDateOfBirth] = useState('');
  const register = useRegister();
  // The document is opened in the language the app is set to, not the browser's.
  const locale = useSettingsStore((s) => s.locale);
  // A first-time sharer has no account, so the share staged by the share-intent
  // flow (T-025) must survive registration too — resume on the ingest screen.
  const pendingShare = useUiStore((s) => s.pendingShare);

  const { fieldErrors, generalError } = formErrors(register.error);

  /*
   * The age refusal, in the user's language (T-113).
   *
   * The API never localizes — nothing in it calls `App::setLocale()`, so its
   * copy is English while this app defaults to Spanish. It sends the minimum as
   * a NUMBER instead, and the sentence is built here. That also means the rule
   * lives in exactly one place: raising it on the server changes what this says,
   * with nothing to keep in step.
   */
  const ageError =
    register.error instanceof AgeRestrictedError
      ? t('auth.register.tooYoung', { age: register.error.minimumAge })
      : undefined;

  function submit() {
    register.mutate(
      {
        name: name.trim(),
        username: username.trim(),
        email: email.trim(),
        password,
        date_of_birth: dateOfBirth.trim(),
      },
      {
        onSuccess: () => router.replace(pendingShare ? '/(main)/share' : '/(main)/map'),
        // The date survives a failed attempt on purpose: it is the one field
        // here that is tedious to retype, and clearing it alongside the password
        // would punish someone whose username was merely taken.
        onError: () => setPassword(''),
      },
    );
  }

  return (
    <AuthScreenLayout title={t('auth.register.title')} subtitle={t('auth.register.subtitle')}>
      {pendingShare ? (
        <View style={styles.banner} accessibilityRole="alert">
          <Text style={styles.bannerText}>{t('auth.login.shareBanner')}</Text>
        </View>
      ) : null}
      <TextField label={t('auth.field.name')} value={name} onChangeText={setName} autoCapitalize="words" error={fieldErrors.name} />
      <TextField label={t('auth.field.username')} value={username} onChangeText={setUsername} error={fieldErrors.username} />
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
        autoComplete="new-password"
        textContentType="newPassword"
        error={fieldErrors.password}
      />
      {/* The age gate (T-113). A neutral age screen: the API checks this and
          throws it away — nothing is stored but the fact that a check passed.
          Same plain TextField the profile screen uses for a birthdate, rather
          than a picker component: it keeps the two date entries consistent and
          adds no native dependency, so this stays a JS-only change. */}
      <TextField
        label={t('auth.field.dateOfBirth')}
        value={dateOfBirth}
        onChangeText={setDateOfBirth}
        placeholder={t('auth.field.dateOfBirthPlaceholder')}
        keyboardType="numbers-and-punctuation"
        autoCapitalize="none"
        error={ageError ?? fieldErrors.date_of_birth}
        testID="register-date-of-birth"
      />
      <Text style={styles.consent}>{t('auth.register.dateOfBirthHint')}</Text>
      {generalError ? <Text style={styles.general}>{t(generalError)}</Text> : null}
      <Button title={t('auth.register.submit')} onPress={submit} loading={register.isPending} />
      {/* Apple 1.2 expects a UGC app to have its users agree to the terms, and
          the terms are where the zero-tolerance clause lives. Placed under the
          button because that is the moment of agreement — a link buried in
          Settings is not consent to anything. */}
      <Text style={styles.consent}>
        {t('auth.register.legalPrefix')}
        <Text
          style={styles.link}
          accessibilityRole="link"
          onPress={() => openWebUrl(legalUrl('terms', locale))}
          testID="register-terms-link"
        >
          {t('auth.register.legalTerms')}
        </Text>
        {t('auth.register.legalJoin')}
        <Text
          style={styles.link}
          accessibilityRole="link"
          onPress={() => openWebUrl(legalUrl('privacy', locale))}
          testID="register-privacy-link"
        >
          {t('auth.register.legalPrivacy')}
        </Text>
        {t('auth.register.legalSuffix')}
      </Text>
      <View style={styles.footer}>
        <Text style={styles.muted}>{t('auth.register.haveAccount')}</Text>
        <Link href="/(auth)/login" style={styles.link}>
          {t('auth.register.login')}
        </Link>
      </View>
    </AuthScreenLayout>
  );
}
