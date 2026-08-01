import { Ionicons } from '@expo/vector-icons';
import { Stack } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Image, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import {
  useConfirmTwoFactor,
  useDisableTwoFactor,
  useEnableTwoFactor,
  useRecoveryCodes,
  useRegenerateRecoveryCodes,
  useTwoFactorStatus,
} from '@/api/hooks/useTwoFactor';
import type { TwoFactorSetup } from '@/api/two-factor';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * Two-step verification (T-068, Settings → Security).
 *
 * Three states in one screen, because they are three points on one path and
 * splitting them across routes would make "I started setup and closed the app"
 * a dead end: OFF (explain, offer to start) → SETTING UP (scan, confirm) → ON
 * (manage codes, turn off).
 *
 * The QR arrives from the API as a PNG data URI, so this renders in the stock
 * `Image` — no QR library, no react-native-svg, no dev-client rebuild.
 */
export default function TwoFactorScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data: status, isLoading } = useTwoFactorStatus();
  const enable = useEnableTwoFactor();
  const confirm = useConfirmTwoFactor();

  const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
  const [code, setCode] = useState('');
  /**
   * Held in screen state, never in the query cache: these are shown once and
   * the cache is persisted to disk (T-103). A cached copy would outlive the
   * screen on the filesystem.
   */
  const [codes, setCodes] = useState<string[] | null>(null);

  const { fieldErrors, generalError } = formErrors(confirm.error);

  function startSetup() {
    enable.mutate(undefined, { onSuccess: setSetup });
  }

  function submitCode() {
    confirm.mutate(code.trim(), {
      onSuccess: (recoveryCodes) => {
        setCodes(recoveryCodes);
        setSetup(null);
        setCode('');
      },
    });
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('twoFactor.title')} />

      <ScrollView contentContainerStyle={styles.scroll}>
        {isLoading ? (
          <ActivityIndicator color={c.primary} style={styles.loading} />
        ) : codes ? (
          <RecoveryCodeList codes={codes} styles={styles} c={c} onDone={() => setCodes(null)} isFresh />
        ) : setup ? (
          <View style={styles.section}>
            <Text style={styles.step}>{t('twoFactor.setup.step1')}</Text>
            <View style={styles.qrCard}>
              <Image
                source={{ uri: setup.qr_png }}
                style={styles.qr}
                accessibilityLabel={t('twoFactor.setup.qrAlt')}
                testID="two-factor-qr"
              />
            </View>
            <Text style={styles.hint}>{t('twoFactor.setup.manualHint')}</Text>
            {/* Selectable rather than a copy button: expo-clipboard is a native
                module, and long-press copy is free. */}
            <Text style={styles.secret} selectable testID="two-factor-secret">
              {setup.secret}
            </Text>

            <Text style={[styles.step, styles.stepSpaced]}>{t('twoFactor.setup.step2')}</Text>
            <TextField
              label={t('twoFactor.setup.codeLabel')}
              value={code}
              onChangeText={setCode}
              keyboardType="number-pad"
              textContentType="oneTimeCode"
              maxLength={6}
              testID="confirm-code"
              error={fieldErrors.code}
            />
            {generalError ? <Text style={styles.error}>{t(generalError)}</Text> : null}
            <Button
              title={t('twoFactor.setup.confirm')}
              onPress={submitCode}
              loading={confirm.isPending}
              disabled={code.trim().length !== 6}
              testID="confirm-submit"
            />
          </View>
        ) : status?.enabled ? (
          <EnabledPanel remaining={status.recovery_codes_remaining} styles={styles} c={c} onCodes={setCodes} />
        ) : (
          <View style={styles.section}>
            <View style={styles.lead}>
              <Ionicons name="shield-checkmark-outline" size={32} color={c.primary} />
              <Text style={styles.leadTitle}>{t('twoFactor.off.title')}</Text>
              <Text style={styles.leadBody}>{t('twoFactor.off.body')}</Text>
            </View>
            <Button
              title={status?.pending ? t('twoFactor.off.resume') : t('twoFactor.off.start')}
              onPress={startSetup}
              loading={enable.isPending}
              testID="start-setup"
            />
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

/** 2FA is on: manage the codes, or turn it off. Both cost a password. */
function EnabledPanel({
  remaining,
  styles,
  c,
  onCodes,
}: {
  remaining: number;
  styles: Styles;
  c: Palette;
  onCodes: (codes: string[]) => void;
}) {
  const t = useT();
  const [action, setAction] = useState<'view' | 'regenerate' | 'disable' | null>(null);
  const [password, setPassword] = useState('');

  const view = useRecoveryCodes();
  const regenerate = useRegenerateRecoveryCodes();
  const disable = useDisableTwoFactor();

  const active = action === 'view' ? view : action === 'regenerate' ? regenerate : disable;
  const { fieldErrors, generalError } = formErrors(active.error);

  function run() {
    const pw = password;
    const done = () => {
      setPassword('');
      setAction(null);
    };

    if (action === 'view') view.mutate(pw, { onSuccess: (codes) => (onCodes(codes), done()) });
    if (action === 'regenerate') regenerate.mutate(pw, { onSuccess: (codes) => (onCodes(codes), done()) });
    if (action === 'disable') disable.mutate(pw, { onSuccess: done });
  }

  function choose(next: 'view' | 'regenerate' | 'disable') {
    view.reset();
    regenerate.reset();
    disable.reset();
    setPassword('');
    setAction(next);
  }

  return (
    <View style={styles.section}>
      <View style={styles.onBadge}>
        <Ionicons name="shield-checkmark" size={20} color={c.green} />
        <Text style={styles.onText}>{t('twoFactor.on.title')}</Text>
      </View>
      <Text style={styles.hint} testID="codes-remaining">
        {t('twoFactor.on.remaining', { count: remaining })}
      </Text>
      {/* A dwindling pool is the failure mode people discover at the worst
          moment — when they have lost the phone and reach for a code. */}
      {remaining <= 2 ? <Text style={styles.warn}>{t('twoFactor.on.lowCodes')}</Text> : null}

      {action === null ? (
        <View style={styles.actions}>
          <Button title={t('twoFactor.on.view')} variant="ghost" onPress={() => choose('view')} testID="view-codes" />
          <Button
            title={t('twoFactor.on.regenerate')}
            variant="ghost"
            onPress={() => choose('regenerate')}
            testID="regenerate-codes"
          />
          <Button
            title={t('twoFactor.on.disable')}
            variant="danger"
            onPress={() => choose('disable')}
            testID="disable-2fa"
          />
        </View>
      ) : (
        <View style={styles.confirmBox}>
          <Text style={styles.confirmTitle}>
            {action === 'disable' ? t('twoFactor.on.disableConfirm') : t('twoFactor.on.passwordPrompt')}
          </Text>
          {action === 'regenerate' ? <Text style={styles.warn}>{t('twoFactor.on.regenerateWarn')}</Text> : null}
          <TextField
            label={t('twoFactor.on.passwordLabel')}
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            textContentType="password"
            testID="password-input"
            error={fieldErrors.password}
          />
          {generalError ? <Text style={styles.error}>{t(generalError)}</Text> : null}
          <Button
            title={action === 'disable' ? t('twoFactor.on.disable') : t('common.continue')}
            variant={action === 'disable' ? 'danger' : 'primary'}
            onPress={run}
            loading={active.isPending}
            disabled={password.length === 0}
            testID="password-submit"
          />
          <Button title={t('common.cancel')} variant="link" onPress={() => setAction(null)} />
        </View>
      )}
    </View>
  );
}

/**
 * The recovery codes.
 *
 * These are the single most losable thing in the flow — shown once, needed
 * exactly when the phone is gone. So they get the loudest treatment on the
 * screen and an explicit acknowledgement, rather than a toast that scrolls away.
 */
function RecoveryCodeList({
  codes,
  styles,
  c,
  onDone,
  isFresh,
}: {
  codes: string[];
  styles: Styles;
  c: Palette;
  onDone: () => void;
  isFresh?: boolean;
}) {
  const t = useT();

  return (
    <View style={styles.section} testID="recovery-codes">
      <View style={styles.lead}>
        <Ionicons name="key-outline" size={32} color={c.gold} />
        <Text style={styles.leadTitle}>{t('twoFactor.codes.title')}</Text>
        <Text style={styles.leadBody}>{isFresh ? t('twoFactor.codes.freshBody') : t('twoFactor.codes.body')}</Text>
      </View>

      <View style={styles.codeCard}>
        {codes.map((code) => (
          <Text key={code} style={styles.code} selectable>
            {code}
          </Text>
        ))}
      </View>

      {codes.length === 0 ? <Text style={styles.warn}>{t('twoFactor.codes.none')}</Text> : null}

      <Button title={t('twoFactor.codes.done')} onPress={onDone} testID="codes-done" />
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { padding: space.md, paddingBottom: space.xxl, gap: space.md },
    loading: { paddingVertical: space.xl },
    section: { gap: space.sm },

    lead: { alignItems: 'center', gap: space.xs, paddingVertical: space.md },
    leadTitle: { ...type.title, color: c.text, textAlign: 'center' },
    leadBody: { ...type.body, color: c.muted, textAlign: 'center', lineHeight: 22 },

    step: { ...type.bodyLg, color: c.text },
    stepSpaced: { marginTop: space.md },
    hint: { ...type.bodySm, color: c.muted, lineHeight: 19 },
    error: { ...type.bodySm, color: c.danger },
    warn: { ...type.bodySm, color: c.gold, fontWeight: '600' },

    // The QR sits on white regardless of theme: scanners read it off the
    // rendered pixels, and a dark-mode surface behind a transparent PNG is the
    // classic way to make a code unscannable.
    qrCard: {
      alignSelf: 'center',
      backgroundColor: '#FFFFFF',
      padding: space.md,
      borderRadius: radius.lg,
      borderWidth: 1,
      borderColor: c.border,
    },
    qr: { width: 200, height: 200 },
    secret: {
      ...type.bodyLg,
      fontFamily: fonts.mono,
      letterSpacing: 1.5,
      color: c.text,
      textAlign: 'center',
      backgroundColor: c.surface,
      borderRadius: radius.sm,
      paddingVertical: space.xs,
    },

    onBadge: { flexDirection: 'row', alignItems: 'center', gap: space.xs },
    onText: { ...type.bodyLg, color: c.green },
    actions: { gap: space.xs, marginTop: space.sm },

    confirmBox: {
      gap: space.sm,
      backgroundColor: c.surface,
      borderRadius: radius.md,
      padding: space.md,
      borderWidth: 1,
      borderColor: c.border,
    },
    confirmTitle: { ...type.bodyLg, color: c.text },

    codeCard: {
      backgroundColor: c.surface,
      borderRadius: radius.md,
      borderWidth: 1,
      borderColor: c.border,
      padding: space.md,
      gap: space.xs,
    },
    code: {
      ...type.body,
      fontFamily: fonts.mono,
      letterSpacing: 1.2,
      color: c.text,
      textAlign: 'center',
    },
  });
