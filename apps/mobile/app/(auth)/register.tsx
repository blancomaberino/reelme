import { Link, router } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { useRegister } from '@/api/hooks/useAuth';
import { AuthScreenLayout, useAuthFormStyles } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { formErrors } from '@/lib/form-errors';

export default function RegisterScreen() {
  const styles = useAuthFormStyles();
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
    <AuthScreenLayout title="Create your account" subtitle="Save the places behind every food video you love.">
      <TextField label="Name" value={name} onChangeText={setName} autoCapitalize="words" error={fieldErrors.name} />
      <TextField label="Username" value={username} onChangeText={setUsername} error={fieldErrors.username} />
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
        autoComplete="new-password"
        textContentType="newPassword"
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
    </AuthScreenLayout>
  );
}
