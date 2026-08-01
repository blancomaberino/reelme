import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { useTwoFactorChallenge } from '@/api/hooks/useTwoFactor';
import { AuthScreenLayout, useAuthFormStyles } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';
import { useUiStore } from '@/stores/ui';

/**
 * The second step of a 2FA login (T-068).
 *
 * Reached only from the login screen, which pushes here carrying the challenge
 * token — the password step is already done. The token is a short-lived cache
 * key with no authority of its own, so it is passed as a route param rather
 * than stored: if the user backs out, it simply expires.
 */
export default function TwoFactorChallengeScreen() {
  const styles = useAuthFormStyles();
  const t = useT();
  const { challenge } = useLocalSearchParams<{ challenge?: string }>();
  const token = (challenge ?? '').trim();

  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState('');
  // Recovery is the escape hatch, not a peer option: showing both fields at
  // once invites people to reach for a one-time code they cannot get back.
  const [useRecovery, setUseRecovery] = useState(false);

  const challengeMutation = useTwoFactorChallenge();
  const { fieldErrors, generalError } = formErrors(challengeMutation.error);
  const pendingShare = useUiStore((s) => s.pendingShare);

  const value = useRecovery ? recovery.trim() : code.trim();
  const ready = useRecovery ? value.length > 0 : value.length === 6;

  function submit() {
    if (!ready) return;
    challengeMutation.mutate(
      useRecovery
        ? { challenge_token: token, recovery_code: value }
        : { challenge_token: token, code: value },
      {
        onSuccess: () => router.replace(pendingShare ? '/(main)/share' : '/(main)/map'),
        // Clear only the field that was wrong, so switching modes doesn't wipe
        // a code the user is mid-way through typing.
        onError: () => (useRecovery ? setRecovery('') : setCode('')),
      },
    );
  }

  function toggleMode() {
    challengeMutation.reset();
    setUseRecovery((v) => !v);
  }

  return (
    <AuthScreenLayout title={t('twoFactor.challenge.title')} subtitle={t('twoFactor.challenge.subtitle')}>
      {useRecovery ? (
        <TextField
          label={t('twoFactor.challenge.recoveryLabel')}
          value={recovery}
          onChangeText={setRecovery}
          autoCapitalize="characters"
          autoCorrect={false}
          testID="recovery-input"
          error={fieldErrors.recovery_code}
        />
      ) : (
        <TextField
          label={t('twoFactor.challenge.codeLabel')}
          value={code}
          onChangeText={setCode}
          keyboardType="number-pad"
          textContentType="oneTimeCode"
          maxLength={6}
          testID="code-input"
          error={fieldErrors.code}
        />
      )}

      {generalError ? <Text style={styles.general}>{t(generalError)}</Text> : null}

      <Button
        title={t('twoFactor.challenge.submit')}
        onPress={submit}
        loading={challengeMutation.isPending}
        disabled={!ready}
        testID="challenge-submit"
      />

      <View style={styles.footer}>
        <Pressable accessibilityRole="button" onPress={toggleMode} testID="toggle-recovery">
          <Text style={styles.link}>
            {useRecovery ? t('twoFactor.challenge.useCode') : t('twoFactor.challenge.useRecovery')}
          </Text>
        </Pressable>
      </View>
    </AuthScreenLayout>
  );
}
