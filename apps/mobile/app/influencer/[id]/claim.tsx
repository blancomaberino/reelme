import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { type ClaimMethod, useClaimInfluencer, useInfluencer, useInfluencerClaim } from '@/api/hooks/useInfluencer';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * Claim an influencer identity as your own (T-038 backend, T-039 UI).
 *
 * Two doors, and the screen shows exactly one at a time because they fail
 * differently:
 *
 *  - **Linked account** — instant when the caller already linked a platform
 *    account whose handle matches. One tap, no waiting.
 *  - **Bio code** — issues a one-time token to paste into the profile bio, then
 *    verified on demand. Asynchronous, and the token has to survive the user
 *    leaving the app to go edit their bio — so it is re-read from the server on
 *    return rather than held in component state.
 *
 * A verified claim is terminal here: the screen hands back to the profile,
 * which now shows the identity as claimed.
 */
export default function ClaimInfluencerScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const influencerId = id ?? '';
  const { data } = useInfluencer(influencerId || null);
  const { data: claim, isLoading } = useInfluencerClaim(influencerId || null);
  const submit = useClaimInfluencer(influencerId);

  const { generalError } = formErrors(submit.error);
  const pendingCode = claim?.status === 'pending' && claim.method === 'bio_code' ? claim.token : null;
  const verified = claim?.status === 'verified';

  const start = (method: ClaimMethod) => submit.mutate({ method });
  const verify = () => submit.mutate({ method: 'bio_code', verify: true });

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('influencer.claim.title')} />

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          <Text style={styles.handle}>@{data?.profile.handle ?? ''}</Text>

          {verified ? (
            <View style={styles.done} testID="claim-verified">
              <Ionicons name="checkmark-circle" size={40} color={c.green} />
              <Text style={styles.doneTitle}>{t('influencer.claim.verified')}</Text>
              <Text style={styles.body}>{t('influencer.claim.verifiedBody')}</Text>
              <Button title={t('influencer.claim.backToProfile')} onPress={() => router.back()} />
            </View>
          ) : pendingCode ? (
            <View style={styles.card} testID="claim-code">
              <Text style={styles.cardTitle}>{t('influencer.claim.codeTitle')}</Text>
              <Text style={styles.body}>{t('influencer.claim.codeBody')}</Text>

              {/* `selectable` gives the platform's own long-press → Copy, which
                  is why there is no copy button: expo-clipboard is a NATIVE
                  module, and a dev-client rebuild is a steep price for a
                  convenience the OS already provides. */}
              <View style={styles.code}>
                <Text style={styles.codeText} selectable accessibilityLabel={pendingCode} testID="claim-code-value">
                  {pendingCode}
                </Text>
              </View>

              <Button
                title={t('influencer.claim.verify')}
                onPress={verify}
                loading={submit.isPending}
              />
              <Text style={styles.hint}>{t('influencer.claim.codeHint')}</Text>
            </View>
          ) : (
            <View style={styles.card} testID="claim-methods">
              <Text style={styles.body}>{t('influencer.claim.intro')}</Text>

              <Button
                title={t('influencer.claim.viaAccount')}
                icon="link-outline"
                onPress={() => start('oauth')}
                loading={submit.isPending}
              />
              <Text style={styles.hint}>{t('influencer.claim.viaAccountHint')}</Text>

              <View style={styles.divider} />

              <Button
                title={t('influencer.claim.viaBio')}
                variant="secondary"
                icon="create-outline"
                onPress={() => start('bio_code')}
                loading={submit.isPending}
              />
              <Text style={styles.hint}>{t('influencer.claim.viaBioHint')}</Text>
            </View>
          )}

          {generalError ? (
            <Text style={styles.error} accessibilityRole="alert">
              {t(generalError)}
            </Text>
          ) : null}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { paddingVertical: space.xl },
    scroll: { padding: space.md, gap: space.md },
    handle: { ...type.title, color: c.text, textAlign: 'center' },
    card: {
      gap: space.xs,
      padding: space.md,
      borderRadius: radius.md,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    cardTitle: { ...type.bodyLg, color: c.text },
    body: { ...type.body, color: c.ink2 },
    hint: { ...type.bodySm, color: c.muted },
    divider: { height: StyleSheet.hairlineWidth, backgroundColor: c.border, marginVertical: space.xs },
    code: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: space.xs,
      padding: space.sm,
      borderRadius: radius.sm,
      backgroundColor: c.surface2,
      borderWidth: 1,
      borderColor: c.primary,
    },
    codeText: { ...type.bodyLg, color: c.text, letterSpacing: 0.5 },
    done: { alignItems: 'center', gap: space.xs, paddingVertical: space.lg },
    doneTitle: { ...type.title, color: c.text, textAlign: 'center' },
    error: { ...type.bodySm, color: c.danger, textAlign: 'center' },
  });
