import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo, useState } from 'react';
import { Keyboard, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { Country } from '@/api/countries';
import { useUpdateMe } from '@/api/hooks/useUpdateMe';
import { Button } from '@/components/button';
import { CountryPicker } from '@/components/country-picker';
import { PickerField, TextField } from '@/components/text-field';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

export default function EditProfileScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const user = useSessionStore((s) => s.user);
  const update = useUpdateMe();

  const [name, setName] = useState(user?.name ?? '');
  const [bio, setBio] = useState(user?.bio ?? '');
  const [birthdate, setBirthdate] = useState(user?.birthdate ?? '');
  const [topics, setTopics] = useState<string[]>(user?.favorite_topics ?? []);
  const [foods, setFoods] = useState<string[]>(user?.favorite_foods ?? []);
  const [isPublic, setIsPublic] = useState(user?.is_public ?? true);
  // The whole {code, name} pair, seeded from the payload the API already
  // localized — so the field shows "España" on open without fetching the
  // 249-row catalog just to translate one code.
  const [country, setCountry] = useState<Country | null>(
    user?.country_code && user.country_name ? { code: user.country_code, name: user.country_name } : null,
  );
  const [pickingCountry, setPickingCountry] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const save = () => {
    setError(null);
    Keyboard.dismiss();
    update.mutate(
      {
        name: name.trim(),
        bio: bio.trim() || null,
        birthdate: birthdate.trim() || null,
        favorite_topics: topics,
        favorite_foods: foods,
        is_public: isPublic,
        // The CODE, never the display name: the API validates against the real
        // ISO list, and null is how the user un-says where they are.
        country_code: country?.code ?? null,
      },
      {
        onSuccess: () => router.back(),
        onError: () => setError(t('editProfile.error')),
      },
    );
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('editProfile.title')} />

      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
        <TextField label={t('editProfile.name')} value={name} onChangeText={setName} autoCapitalize="words" />
        <TextField
          label={t('editProfile.bio')}
          value={bio}
          onChangeText={setBio}
          placeholder={t('editProfile.bioPlaceholder')}
          autoCapitalize="sentences"
          multiline
        />
        <TextField
          label={t('editProfile.birthdate')}
          value={birthdate}
          onChangeText={setBirthdate}
          placeholder={t('editProfile.birthdatePlaceholder')}
          keyboardType="numbers-and-punctuation"
          autoCapitalize="none"
        />
        {user?.age != null ? <Text style={styles.age}>{t('editProfile.age', { age: user.age })}</Text> : null}

        <PickerField
          label={t('editProfile.country')}
          value={country?.name}
          placeholder={t('editProfile.countryPlaceholder')}
          onPress={() => setPickingCountry(true)}
          testID="country-field"
        />
        <Text style={styles.fieldHint}>{t('editProfile.countryHint')}</Text>

        <TagEditor
          label={t('editProfile.topics')}
          placeholder={t('editProfile.topicsPlaceholder')}
          items={topics}
          onChange={setTopics}
          addLabel={t('editProfile.add')}
          c={c}
          styles={styles}
        />
        <TagEditor
          label={t('editProfile.foods')}
          placeholder={t('editProfile.foodsPlaceholder')}
          items={foods}
          onChange={setFoods}
          addLabel={t('editProfile.add')}
          c={c}
          styles={styles}
        />

        <View style={styles.section}>
          <Text style={styles.sectionLabel}>{t('editProfile.privacy')}</Text>
          <View style={styles.privacyGroup}>
            <PrivacyOption
              title={t('editProfile.public')}
              hint={t('editProfile.publicHint')}
              icon="earth"
              selected={isPublic}
              onPress={() => setIsPublic(true)}
              c={c}
              styles={styles}
            />
            <PrivacyOption
              title={t('editProfile.private')}
              hint={t('editProfile.privateHint')}
              icon="lock-closed"
              selected={!isPublic}
              onPress={() => setIsPublic(false)}
              c={c}
              styles={styles}
            />
          </View>
        </View>

        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Button title={t('editProfile.save')} onPress={save} loading={update.isPending} />

        <CountryPicker
          visible={pickingCountry}
          onClose={() => setPickingCountry(false)}
          value={country?.code ?? null}
          onSelect={setCountry}
        />
      </ScrollView>
    </SafeAreaView>
  );
}

/** A labelled list of removable chips with an inline "add" input. */
function TagEditor({
  label,
  placeholder,
  items,
  onChange,
  addLabel,
  c,
  styles,
}: {
  label: string;
  placeholder: string;
  items: string[];
  onChange: (next: string[]) => void;
  addLabel: string;
  c: Palette;
  styles: Styles;
}) {
  const [draft, setDraft] = useState('');
  const add = () => {
    const v = draft.trim();
    if (v && !items.includes(v) && items.length < 20) onChange([...items, v]);
    setDraft('');
  };
  return (
    <View style={styles.section}>
      <Text style={styles.sectionLabel}>{label}</Text>
      {items.length > 0 ? (
        <View style={styles.chips}>
          {items.map((it) => (
            <Pressable
              key={it}
              accessibilityRole="button"
              accessibilityLabel={`${it} ✕`}
              onPress={() => onChange(items.filter((x) => x !== it))}
              style={styles.chip}
            >
              <Text style={styles.chipText}>{it}</Text>
              <Ionicons name="close" size={14} color={c.secondary} />
            </Pressable>
          ))}
        </View>
      ) : null}
      <View style={styles.addRow}>
        <TextInput
          style={styles.addInput}
          value={draft}
          onChangeText={setDraft}
          placeholder={placeholder}
          placeholderTextColor={c.placeholder}
          autoCapitalize="none"
          returnKeyType="done"
          onSubmitEditing={add}
        />
        <Pressable accessibilityRole="button" accessibilityLabel={addLabel} onPress={add} style={styles.addBtn}>
          <Text style={styles.addBtnText}>{addLabel}</Text>
        </Pressable>
      </View>
    </View>
  );
}

/** One selectable visibility choice (public / private) with an icon + hint. */
function PrivacyOption({
  title,
  hint,
  icon,
  selected,
  onPress,
  c,
  styles,
}: {
  title: string;
  hint: string;
  icon: keyof typeof Ionicons.glyphMap;
  selected: boolean;
  onPress: () => void;
  c: Palette;
  styles: Styles;
}) {
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ selected }}
      accessibilityLabel={title}
      accessibilityHint={hint}
      onPress={onPress}
      style={({ pressed }) => [styles.privacyOption, selected && styles.privacyOptionOn, pressed && styles.pressed]}
    >
      <Ionicons name={icon} size={20} color={selected ? c.primary : c.muted} />
      <View style={styles.privacyText}>
        <Text style={[styles.privacyTitle, selected && styles.privacyTitleOn]}>{title}</Text>
        <Text style={styles.privacyHint}>{hint}</Text>
      </View>
      {selected ? <Ionicons name="checkmark-circle" size={20} color={c.primary} /> : null}
    </Pressable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { padding: 20, gap: 14, paddingBottom: 40 },
    age: { fontSize: 13, color: c.muted, marginTop: -6 },
    fieldHint: { fontSize: 13, color: c.muted, marginTop: -6 },
    error: { color: c.danger, fontSize: 14 },
    section: { gap: 8 },
    sectionLabel: { fontSize: 13, fontWeight: '600', color: c.text },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    chip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 5,
      backgroundColor: c.secondarySoft,
      borderRadius: 999,
      paddingHorizontal: 12,
      paddingVertical: 6,
    },
    chipText: { color: c.secondary, fontSize: 14, fontWeight: '600' },
    privacyGroup: { gap: 10 },
    privacyOption: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      paddingHorizontal: 14,
      paddingVertical: 13,
      borderRadius: 14,
      borderWidth: 1.5,
      borderColor: c.border,
      backgroundColor: c.surface,
    },
    privacyOptionOn: { borderColor: c.primary, backgroundColor: c.primarySoft },
    privacyText: { flex: 1, gap: 2 },
    privacyTitle: { fontSize: 15, fontWeight: '700', color: c.text },
    privacyTitleOn: { color: c.primary },
    privacyHint: { fontSize: 13, color: c.muted },
    pressed: { opacity: 0.7 },
    addRow: { flexDirection: 'row', gap: 8, alignItems: 'center' },
    addInput: {
      flex: 1,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      borderRadius: 12,
      paddingHorizontal: 14,
      paddingVertical: 12,
      fontSize: 15,
      color: c.text,
      backgroundColor: c.surface,
    },
    addBtn: { paddingHorizontal: 16, paddingVertical: 12, borderRadius: 12, borderWidth: 1.5, borderColor: c.primary },
    addBtnText: { color: c.primary, fontWeight: '700', fontSize: 15 },
  });
