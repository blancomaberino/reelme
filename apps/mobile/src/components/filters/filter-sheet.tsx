import { Ionicons } from '@expo/vector-icons';
import { type ReactNode, useMemo } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

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
  const { height: screenH } = useWindowDimensions();
  const insets = useSafeAreaInsets();

  // A stable, near-full-height sheet: the tag list is dynamic and can be long, so
  // sizing to content collided the last suggestion row with the pinned footer.
  // A concrete pixel height (not a %, which never resolved through the modal's
  // hierarchy) makes the column deterministic — fixed header, flex:1 scroll body,
  // pinned footer — so suggestions always scroll clear of "Ver resultados".
  const sheetHeight = Math.round(screenH * 0.88);

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <View style={styles.root}>
        <Pressable style={[StyleSheet.absoluteFill, styles.backdrop]} onPress={onClose} />
        <View style={[styles.sheet, { height: sheetHeight }]}>
          <View style={styles.handle} />
          <View style={styles.header}>
            <Text style={styles.title}>{title ?? t('filters.title')}</Text>
            {activeCount > 0 ? (
              <Pressable accessibilityRole="button" accessibilityLabel={t('filters.clear')} onPress={onClear} hitSlop={8}>
                <Text style={styles.clear}>{t('filters.clear')}</Text>
              </Pressable>
            ) : null}
          </View>

          {/* automaticallyAdjustKeyboardInsets keeps the focused tag-search input
              above the keyboard by insetting the scroll — without shifting the
              whole (tall) sheet off the top of the screen. */}
          <ScrollView
            style={styles.bodyScroll}
            contentContainerStyle={styles.body}
            showsVerticalScrollIndicator={false}
            keyboardShouldPersistTaps="handled"
            automaticallyAdjustKeyboardInsets
          >
            {children}
          </ScrollView>

          {/* Pad past the home indicator manually: a Modal renders outside the
              safe-area provider, so SafeAreaView reports zero bottom inset here. */}
          <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, 12) + 8 }]}>
            <Button title={t('filters.apply')} onPress={onClose} />
          </View>
        </View>
      </View>
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
    // Full-screen overlay: dim backdrop fills it, the sheet is pinned to the bottom.
    root: { flex: 1, justifyContent: 'flex-end' },
    backdrop: { backgroundColor: 'rgba(0,0,0,0.35)' },
    sheet: {
      backgroundColor: c.background,
      borderTopLeftRadius: 22,
      borderTopRightRadius: 22,
      paddingHorizontal: 20,
      paddingTop: 8,
    },
    handle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: c.border, marginBottom: 10 },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 },
    title: { fontFamily: fonts.display, fontSize: 21, fontWeight: '700', color: c.text },
    clear: { color: c.primary, fontSize: 15, fontWeight: '700' },
    // flex:1 fills the space between the fixed header and pinned footer inside the
    // fixed-height sheet, so the body scrolls and the footer never overlaps it.
    bodyScroll: { flex: 1 },
    body: { paddingTop: 8, paddingBottom: 16 },
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
    footer: { paddingTop: 10 },
  });
