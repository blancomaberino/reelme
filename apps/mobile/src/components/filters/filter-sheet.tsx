import { Ionicons } from '@expo/vector-icons';
import { type ReactNode, useMemo } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button } from '@/components/button';
import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

type SheetProps = {
  visible: boolean;
  onClose: () => void;
  /** Sheet heading; defaults to the shared "Filters" label. */
  title?: string;
  /** Number of active filters — enables the header "Clear" action when > 0. */
  activeCount: number;
  /** Reset the sheet's filters (facets only; screen decides what that means). */
  onClear: () => void;
  children: ReactNode;
};

/**
 * Shared filter bottom-sheet used by the map and My-places screens (T-032/T-071
 * follow-up). Replaces the ever-growing horizontal chip bar: a single "Filtros"
 * button opens this, options live in grouped sections, and only applied filters
 * stay visible as removable chips in the trigger bar. RN Modal slide-up to match
 * the app's other sheets (menu / save-to-list), so no gorhom provider is needed.
 */
export function FilterSheet({ visible, onClose, title, activeCount, onClear, children }: SheetProps) {
  const t = useT();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} />
      {/* Lift the sheet above the keyboard so the tag-search input stays visible. */}
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <SafeAreaView style={styles.sheet} edges={['bottom']}>
          <View style={styles.handle} />
          <View style={styles.header}>
            <Text style={styles.title}>{title ?? t('filters.title')}</Text>
            {activeCount > 0 ? (
              <Pressable accessibilityRole="button" accessibilityLabel={t('filters.clear')} onPress={onClear} hitSlop={8}>
                <Text style={styles.clear}>{t('filters.clear')}</Text>
              </Pressable>
            ) : null}
          </View>

          <ScrollView
            style={styles.bodyScroll}
            contentContainerStyle={styles.body}
            showsVerticalScrollIndicator={false}
            keyboardShouldPersistTaps="handled"
          >
            {children}
          </ScrollView>

          <View style={styles.footer}>
            <Button title={t('filters.apply')} onPress={onClose} />
          </View>
        </SafeAreaView>
      </KeyboardAvoidingView>
    </Modal>
  );
}

/** A labelled section of option pills inside the sheet. */
export function FilterGroup({ label, children }: { label: string; children: ReactNode }) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  return (
    <View style={styles.group}>
      <Text style={styles.groupLabel}>{label}</Text>
      <View style={styles.options}>{children}</View>
    </View>
  );
}

/** A selectable pill; fills with the accent when selected. */
export function OptionPill({
  label,
  selected,
  icon,
  onPress,
}: {
  label: string;
  selected: boolean;
  icon?: keyof typeof Ionicons.glyphMap;
  onPress: () => void;
}) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      accessibilityLabel={label}
      onPress={onPress}
      style={({ pressed }) => [styles.pill, selected && styles.pillActive, pressed && styles.pillPressed]}
    >
      {icon ? <Ionicons name={icon} size={14} color={selected ? c.onPrimary : c.text} /> : null}
      <Text style={[styles.pillLabel, selected && styles.pillLabelActive]}>{label}</Text>
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)' },
    sheet: {
      backgroundColor: c.background,
      borderTopLeftRadius: 22,
      borderTopRightRadius: 22,
      paddingHorizontal: 20,
      paddingTop: 8,
      // Cap the height so a long tag list scrolls with the footer pinned below.
      maxHeight: '85%',
    },
    handle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: c.border, marginBottom: 10 },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 },
    title: { fontFamily: fonts.display, fontSize: 21, fontWeight: '700', color: c.text },
    clear: { color: c.primary, fontSize: 15, fontWeight: '700' },
    // flexShrink lets the scroll area collapse within the sheet's maxHeight so
    // the footer button stays on screen instead of being pushed off the bottom.
    bodyScroll: { flexShrink: 1 },
    body: { paddingTop: 8, paddingBottom: 8 },
    group: { marginBottom: 20 },
    groupLabel: {
      fontSize: 12,
      fontWeight: '700',
      letterSpacing: 0.5,
      textTransform: 'uppercase',
      color: c.muted,
      marginBottom: 12,
    },
    options: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    pill: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      paddingHorizontal: 14,
      paddingVertical: 9,
      borderRadius: 999,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    pillActive: { backgroundColor: c.primary, borderColor: c.primary },
    pillPressed: { opacity: 0.7 },
    pillLabel: { color: c.text, fontSize: 14, fontWeight: '600' },
    pillLabelActive: { color: c.onPrimary },
    footer: { paddingTop: 10, paddingBottom: 4 },
  });
