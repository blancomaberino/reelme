import { Link, router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { useRegister } from '@/api/hooks/useAuth';
import { AuthScreenLayout, useAuthFormStyles } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';

export default function RegisterScreen() {
  const styles = useAuthFormStyles();
  const t = useT();
  const [name, setName] = useState('');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const register = useRegister();

  const { fieldErrors, generalError } = formErrors(register.error);

  function submit() {
    register.mutate(
      { name: name.trim(), username: username.trim(), email: email.trim(), password },
      {
        onSuccess: () => router.replace('/(main)/map'),
        onError: () => setPassword(''),
      },
    );
  }

  return (
    <AuthScreenLayout title={t('auth.register.title')} subtitle={t('auth.register.subtitle')}>
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
      {generalError ? <Text style={styles.general}>{generalError}</Text> : null}
      <Button title={t('auth.register.submit')} onPress={submit} loading={register.isPending} />
      <View style={styles.footer}>
        <Text style={styles.muted}>{t('auth.register.haveAccount')}</Text>
        <Link href="/(auth)/login" style={styles.link}>
          {t('auth.register.login')}
        </Link>
      </View>
    </AuthScreenLayout>
  );
}
