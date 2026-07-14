import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useInviteFriends } from '@/api/hooks/useInviteFriends';
import { Button } from '@/components/button';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function InviteScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [email, setEmail] = useState('');
  const [emails, setEmails] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const invite = useInviteFriends();

  const addEmail = () => {
    const v = email.trim().toLowerCase();
    if (v === '') return;
    if (!EMAIL_RE.test(v)) {
      setError(t('invite.invalidEmail'));
      return;
    }
    setError(null);
    if (!emails.includes(v)) setEmails((prev) => [...prev, v]);
    setEmail('');
  };

  const removeEmail = (target: string) => setEmails((prev) => prev.filter((e) => e !== target));

  const send = () => {
    if (emails.length === 0 || invite.isPending) return;
    invite.mutate(emails, {
      onSuccess: () => {
        Alert.alert(t('invite.sentTitle'), t('invite.sentBody'));
        router.back();
      },
      onError: () => setError(t('invite.error')),
    });
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={() => router.back()} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title}>{t('invite.title')}</Text>
        <View style={styles.spacer} />
      </View>

      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
        <Text style={styles.hint}>{t('invite.hint')}</Text>

        <View style={styles.inputRow}>
          <TextInput
            value={email}
            onChangeText={setEmail}
            onSubmitEditing={addEmail}
            placeholder={t('invite.placeholder')}
            placeholderTextColor={c.muted}
            keyboardType="email-address"
            autoCapitalize="none"
            autoComplete="email"
            returnKeyType="done"
            style={styles.input}
          />
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('invite.add')}
            onPress={addEmail}
            disabled={email.trim() === ''}
            style={({ pressed }) => [styles.addButton, email.trim() === '' && styles.addDisabled, pressed && styles.pressed]}
          >
            <Ionicons name="add" size={20} color={c.onPrimary} />
          </Pressable>
        </View>

        {error ? <Text style={styles.error}>{error}</Text> : null}

        {emails.length > 0 ? (
          <View style={styles.chips}>
            {emails.map((e) => (
              <View key={e} style={styles.chip}>
                <Text style={styles.chipLabel} numberOfLines={1}>
                  {e}
                </Text>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={t('invite.remove', { email: e })}
                  onPress={() => removeEmail(e)}
                  hitSlop={8}
                  style={styles.remove}
                >
                  <Ionicons name="close" size={14} color={c.secondary} />
                </Pressable>
              </View>
            ))}
          </View>
        ) : null}
      </ScrollView>

      <View style={styles.footer}>
        <Button
          title={t('invite.send')}
          onPress={send}
          loading={invite.isPending}
          disabled={emails.length === 0}
        />
      </View>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 12 },
    title: { flex: 1, fontSize: 20, fontWeight: '700', color: c.text },
    spacer: { width: 26 },
    scroll: { padding: 20, gap: 14 },
    hint: { fontSize: 14, color: c.muted },
    inputRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    input: {
      flex: 1,
      height: 46,
      paddingHorizontal: 14,
      borderRadius: 12,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      color: c.text,
      fontSize: 15,
    },
    addButton: {
      width: 46,
      height: 46,
      borderRadius: 12,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primary,
    },
    addDisabled: { opacity: 0.4 },
    pressed: { opacity: 0.6 },
    error: { fontSize: 13, color: c.danger },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    chip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      paddingLeft: 12,
      paddingRight: 6,
      paddingVertical: 6,
      borderRadius: 999,
      backgroundColor: c.secondarySoft,
    },
    chipLabel: { color: c.secondary, fontSize: 13, fontWeight: '600', maxWidth: 220 },
    remove: { padding: 2 },
    footer: { padding: 20, gap: 12 },
  });
