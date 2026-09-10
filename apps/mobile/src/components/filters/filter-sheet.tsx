import { type ReactNode, useMemo } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { Button } from '@/components/button';
import { SheetShell } from '@/components/sheet-shell';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

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
    <SheetShell
      visible={visible}
      onClose={onClose}
      title={title ?? t('filters.title')}
      action={activeCount > 0 ? { label: t('filters.clear'), onPress: onClear } : null}
      footer={<Button title={t('filters.apply')} onPress={onClose} />}
    >
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
    </SheetShell>
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

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    // The sheet chrome (backdrop, card, handle, header, footer) lives in
    // SheetShell — what's left here is only the filter BODY.
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
  });
