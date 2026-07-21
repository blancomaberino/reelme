import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { useResendVerification, useVerifyEmail } from '@/api/hooks/useAuth';
import { AuthScreenLayout, useAuthFormStyles } from '@/components/auth-screen-layout';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';
import { useUiStore } from '@/stores/ui';

const RESEND_COOLDOWN = 60;

export default function VerifyEmailScreen() {
  const styles = useAuthFormStyles();
  const t = useT();
  const { email } = useLocalSearchParams<{ email?: string }>();
  const address = (email ?? '').trim();
  const [code, setCode] = useState('');
  const [cooldown, setCooldown] = useState(0);
  const verify = useVerifyEmail();
  const resend = useResendVerification();
  // Resume a share staged before sign-up (T-025) once the account is confirmed.
  const pendingShare = useUiStore((s) => s.pendingShare);

  const { fieldErrors, generalError } = formErrors(verify.error);

  // Tick down the resend cooldown. setState lives in the interval callback (not
  // the effect body), so react-hooks/set-state-in-effect is satisfied.
  const cooling = cooldown > 0;
  useEffect(() => {
    if (!cooling) return;
    const id = setInterval(() => setCooldown((s) => (s <= 1 ? 0 : s - 1)), 1000);
    return () => clearInterval(id);
  }, [cooling]);

  function submit() {
    verify.mutate(
      { email: address, code: code.trim() },
      { onSuccess: () => router.replace(pendingShare ? '/(main)/share' : '/(main)/map') },
    );
  }

  function onResend() {
    if (cooling) return;
    setCooldown(RESEND_COOLDOWN);
    resend.mutate(address);
  }

  return (
    <AuthScreenLayout title={t('verify.title')} subtitle={t('verify.subtitle', { email: address })}>
      <TextField
        label={t('verify.codeLabel')}
        value={code}
        onChangeText={setCode}
        keyboardType="number-pad"
        textContentType="oneTimeCode"
        maxLength={6}
        error={fieldErrors.code}
      />
      {generalError ? <Text style={styles.general}>{generalError}</Text> : null}
      <Button
        title={t('verify.submit')}
        onPress={submit}
        loading={verify.isPending}
        disabled={code.trim().length !== 6}
      />
      <View style={styles.footer}>
        <Pressable accessibilityRole="button" onPress={onResend} disabled={cooling}>
          <Text style={cooling ? styles.muted : styles.link}>
            {cooling ? t('verify.resendIn', { seconds: cooldown }) : t('verify.resend')}
          </Text>
        </Pressable>
      </View>
      {resend.isSuccess ? <Text style={styles.muted}>{t('verify.resent')}</Text> : null}
    </AuthScreenLayout>
  );
}
