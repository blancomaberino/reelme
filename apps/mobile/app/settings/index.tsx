import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAnalysisModels, useSetAnalysisModel } from '@/api/hooks/useAnalysisModels';
import { useTwoFactorStatus } from '@/api/hooks/useTwoFactor';
import { useT } from '@/i18n';
import { type LegalDocument, legalUrl } from '@/lib/legal';
import { openWebUrl } from '@/lib/linking';
import { useSessionStore } from '@/stores/session';
import { CURRENCIES, type Currency, type Locale, useSettingsStore } from '@/stores/settings';
import { type Palette, useColors } from '@/theme/colors';
import { space, type } from '@/theme/tokens';
import { ScreenHeader } from '@/components/screen-header';

const LOCALES: { value: Locale; labelKey: 'settings.language.es' | 'settings.language.en' }[] = [
  { value: 'es', labelKey: 'settings.language.es' },
  { value: 'en', labelKey: 'settings.language.en' },
];

const LEGAL_ROWS: {
  doc: LegalDocument;
  labelKey: 'settings.legal.privacy' | 'settings.legal.terms';
  testID: string;
}[] = [
  { doc: 'privacy', labelKey: 'settings.legal.privacy', testID: 'settings-legal-privacy' },
  { doc: 'terms', labelKey: 'settings.legal.terms', testID: 'settings-legal-terms' },
];

export default function SettingsScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const locale = useSettingsStore((s) => s.locale);
  const setLocale = useSettingsStore((s) => s.setLocale);
  const currency = useSettingsStore((s) => s.currency);
  const setCurrency = useSettingsStore((s) => s.setCurrency);

  // The model picker is account state, not device state — so unlike language
  // and currency it is authed-only and lives on the server.
  const authed = useSessionStore((s) => s.status === 'authed');
  const preferred = useSessionStore((s) => s.user?.preferred_analysis_model) ?? 'auto';
  const { data: models, isLoading: modelsLoading } = useAnalysisModels({ enabled: authed });
  const setModel = useSetAnalysisModel();
  const { data: twoFactor } = useTwoFactorStatus({ enabled: authed });

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('settings.title')} />

      <ScrollView contentContainerStyle={styles.scroll}>
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>{t('settings.language')}</Text>
        <Text style={styles.hint}>{t('settings.languageHint')}</Text>
        <View style={styles.group}>
          {LOCALES.map((opt) => {
            const selected = locale === opt.value;
            return (
              <Pressable
                key={opt.value}
                accessibilityRole="radio"
                accessibilityState={{ selected }}
                accessibilityLabel={t(opt.labelKey)}
                onPress={() => setLocale(opt.value)}
                style={({ pressed }) => [styles.option, pressed && styles.pressed]}
              >
                <Text style={styles.optionLabel}>{t(opt.labelKey)}</Text>
                {selected ? <Ionicons name="checkmark" size={20} color={c.primary} /> : null}
              </Pressable>
            );
          })}
        </View>
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionTitle}>{t('settings.currency')}</Text>
        <Text style={styles.hint}>{t('settings.currencyHint')}</Text>
        <View style={styles.group}>
          {CURRENCIES.map((sym: Currency) => {
            const selected = currency === sym;
            return (
              <Pressable
                key={sym}
                accessibilityRole="radio"
                accessibilityState={{ selected }}
                accessibilityLabel={sym}
                onPress={() => setCurrency(sym)}
                style={({ pressed }) => [styles.option, pressed && styles.pressed]}
              >
                <Text style={styles.optionLabel}>{sym}{sym}{sym}</Text>
                {selected ? <Ionicons name="checkmark" size={20} color={c.primary} /> : null}
              </Pressable>
            );
          })}
        </View>
      </View>

      {authed ? (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>{t('settings.model')}</Text>
          <Text style={styles.hint}>{t('settings.modelHint')}</Text>
          {modelsLoading ? (
            <ActivityIndicator color={c.primary} style={styles.modelsLoading} />
          ) : (
            <View style={styles.group}>
              {(models ?? []).map((m) => {
                const selected = preferred === m.id;
                return (
                  <Pressable
                    key={m.id}
                    accessibilityRole="radio"
                    accessibilityState={{ selected, disabled: !m.available }}
                    accessibilityLabel={m.label}
                    // An unavailable model is shown but not selectable: hiding
                    // it would make a model the user picked yesterday silently
                    // vanish today, with no explanation for why analysis moved.
                    disabled={!m.available || setModel.isPending}
                    onPress={() => setModel.mutate(m.id)}
                    style={({ pressed }) => [styles.option, pressed && styles.pressed, !m.available && styles.unavailable]}
                  >
                    <View style={styles.modelText}>
                      <Text style={styles.optionLabel}>{m.label}</Text>
                      <Text style={styles.modelProvider}>
                        {m.available ? m.provider : t('settings.modelUnavailable')}
                      </Text>
                    </View>
                    {selected ? <Ionicons name="checkmark" size={20} color={c.primary} /> : null}
                  </Pressable>
                );
              })}
            </View>
          )}
        </View>
      ) : null}

      {authed ? (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>{t('settings.security')}</Text>
          <View style={styles.group}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('settings.twoFactor')}
              onPress={() => router.push('/settings/two-factor')}
              style={({ pressed }) => [styles.option, pressed && styles.pressed]}
              testID="settings-two-factor"
            >
              <Text style={styles.optionLabel}>{t('settings.twoFactor')}</Text>
              <View style={styles.rowRight}>
                {/* State on the row itself: whether the second factor is on is
                    the one thing worth knowing without opening the screen. */}
                <Text style={twoFactor?.enabled ? styles.onValue : styles.offValue}>
                  {twoFactor?.enabled ? t('settings.twoFactorOn') : t('settings.twoFactorOff')}
                </Text>
                <Ionicons name="chevron-forward" size={18} color={c.muted} />
              </View>
            </Pressable>

            {/* Privacy & data (T-039). Authed-only alongside two-step: both
                rows act on an account, and a guest has none. */}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('settings.privacy')}
              onPress={() => router.push('/settings/privacy')}
              style={({ pressed }) => [styles.option, pressed && styles.pressed]}
              testID="settings-privacy"
            >
              <Text style={styles.optionLabel}>{t('settings.privacy')}</Text>
              <Ionicons name="chevron-forward" size={18} color={c.muted} />
            </Pressable>

            {/* Blocked accounts (T-054, Apple 1.2). This row is what makes a
                block REVERSIBLE: a blocked profile is a 404 for the blocker, so
                there is no other route back to it. A block you cannot undo is a
                worse product than no block at all. */}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('block.settingsRow')}
              onPress={() => router.push('/settings/blocked')}
              style={({ pressed }) => [styles.option, pressed && styles.pressed]}
              testID="settings-blocked"
            >
              <Text style={styles.optionLabel}>{t('block.settingsRow')}</Text>
              <Ionicons name="chevron-forward" size={18} color={c.muted} />
            </Pressable>
          </View>
        </View>
      ) : null}

      {/* Legal (T-054, Apple 5.1.1). Deliberately OUTSIDE the `authed` gate,
          unlike the rows above: the privacy policy has to be readable by
          someone deciding whether to sign up at all, and an App Review reviewer
          opens it before creating an account. Gating it behind login is the
          single most common way this requirement gets missed.

          These leave the app for the browser — the documents are published by
          the API so the store listing and the in-app link are the same URL and
          cannot drift apart. */}
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>{t('settings.legal')}</Text>
        <View style={styles.group}>
          {LEGAL_ROWS.map(({ doc, labelKey, testID }) => (
            <Pressable
              key={doc}
              accessibilityRole="link"
              accessibilityLabel={t(labelKey)}
              accessibilityHint={t('settings.legal.opensInBrowser')}
              onPress={() => openWebUrl(legalUrl(doc, locale))}
              style={({ pressed }) => [styles.option, pressed && styles.pressed]}
              testID={testID}
            >
              <Text style={styles.optionLabel}>{t(labelKey)}</Text>
              <Ionicons name="open-outline" size={18} color={c.muted} />
            </Pressable>
          ))}
        </View>
      </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { paddingBottom: space.xl },
    modelsLoading: { paddingVertical: space.lg },
    modelText: { flex: 1, gap: space.xxs },
    modelProvider: { ...type.bodySm, color: c.muted },
    unavailable: { opacity: 0.45 },
    section: { paddingHorizontal: 20, paddingTop: 12, gap: 6 },
    sectionTitle: { fontSize: 13, fontWeight: '700', letterSpacing: 0.4, textTransform: 'uppercase', color: c.muted },
    hint: { fontSize: 14, color: c.muted, marginBottom: 6 },
    group: {
      backgroundColor: c.surface,
      borderRadius: 14,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      overflow: 'hidden',
    },
    option: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      paddingHorizontal: 16,
      paddingVertical: 15,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    pressed: { opacity: 0.6 },
    optionLabel: { fontSize: 16, color: c.text },
    rowRight: { flexDirection: 'row', alignItems: 'center', gap: space.xs },
    onValue: { ...type.bodySm, color: c.green, fontWeight: '600' },
    offValue: { ...type.bodySm, color: c.muted },
  });
