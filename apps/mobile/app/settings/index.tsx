import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useT } from '@/i18n';
import { CURRENCIES, type Currency, type Locale, useSettingsStore } from '@/stores/settings';
import { type Palette, useColors } from '@/theme/colors';

const LOCALES: { value: Locale; labelKey: 'settings.language.es' | 'settings.language.en' }[] = [
  { value: 'es', labelKey: 'settings.language.es' },
  { value: 'en', labelKey: 'settings.language.en' },
];

export default function SettingsScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const locale = useSettingsStore((s) => s.locale);
  const setLocale = useSettingsStore((s) => s.setLocale);
  const currency = useSettingsStore((s) => s.currency);
  const setCurrency = useSettingsStore((s) => s.setCurrency);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={() => router.back()} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title}>{t('settings.title')}</Text>
      </View>

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
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 12 },
    title: { fontSize: 22, fontWeight: '700', color: c.text },
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
  });
