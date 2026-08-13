import { Ionicons } from '@expo/vector-icons';
import { type ReactNode, useMemo } from 'react';
import { Modal, Pressable, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

type Props = {
  visible: boolean;
  onClose: () => void;
  /** Sheet heading. */
  title: string;
  /** Trailing header action ("Clear", "Remove"). Omit or pass null to hide it. */
  action?: { label: string; onPress: () => void } | null;
  /**
   * Pinned footer. Omit for a sheet whose rows commit on tap — the shell still
   * pads past the home indicator so the last row clears it.
   */
  footer?: ReactNode;
  /**
   * Force the header ✕ even though a footer is present.
   *
   * The default (✕ only when there is no footer) assumes a footer is a commit
   * button that also dismisses. That stops being true the moment the button can
   * be DISABLED — the suggest-edit form's Save is disabled until something
   * changes, so a sheet opened and read leaves a dead button and no visible exit
   * at all. Opt in there rather than always showing both, which would give the
   * filter sheet two ways to leave and no way to tell them apart.
   */
  showClose?: boolean;
  /** Fills the space between the fixed header and the footer. */
  children: ReactNode;
};

/**
 * The app's bottom-sheet chrome: dim backdrop, rounded card pinned to the
 * bottom, grab handle, fixed header, flex:1 body, pinned footer.
 *
 * Extracted from the filter sheet when the country picker needed the same
 * chrome around a different body — a FlatList of 249 rows rather than a
 * ScrollView of pill groups, which cannot nest inside one. Two copies of a
 * modal is how the second one ends up 2pt off and a scheme behind, so the
 * chrome lives here and the bodies differ where they genuinely differ.
 *
 * RN `Modal` rather than a gesture library: it is what every other sheet in the
 * app uses, so no provider is needed.
 */
export function SheetShell({ visible, onClose, title, action, footer, showClose, children }: Props) {
  const t = useT();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { height: screenH } = useWindowDimensions();
  const insets = useSafeAreaInsets();

  // A stable, near-full-height sheet. Sizing to content collided long bodies
  // with the pinned footer; a concrete pixel height (not a %, which never
  // resolved through the modal's hierarchy) makes the column deterministic.
  const sheetHeight = Math.round(screenH * 0.88);
  // A Modal renders outside the safe-area provider, so SafeAreaView reports a
  // zero bottom inset here — both branches below have to pad past the home
  // indicator themselves.
  const bottomInset = Math.max(insets.bottom, space.sm);

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <View style={styles.root}>
        {/* Labelled, because it is a control: unlabelled it is invisible to a
            screen reader, and for a sheet with no footer it was the ONLY way
            out short of the Android hardware back button. */}
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('common.close')}
          style={[StyleSheet.absoluteFill, styles.backdrop]}
          onPress={onClose}
        />
        <View style={[styles.sheet, { height: sheetHeight }]}>
          <View style={styles.handle} />
          <View style={styles.header}>
            <Text style={styles.title} numberOfLines={1}>
              {title}
            </Text>
            <View style={styles.headerActions}>
              {action ? (
                <Pressable accessibilityRole="button" accessibilityLabel={action.label} onPress={action.onPress} hitSlop={8}>
                  <Text style={styles.action}>{action.label}</Text>
                </Pressable>
              ) : null}
              {/* A visible exit. Hidden only when the footer IS one: a commit
                  button that also dismisses (the filter sheet's "Apply") makes a
                  second control beside it two ways to leave and no way to tell
                  them apart. A footer whose button can be disabled is not a way
                  out, so those callers pass `showClose`. */}
              {footer && !showClose ? null : (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={t('common.close')}
                  testID="sheet-close"
                  onPress={onClose}
                  hitSlop={8}
                >
                  <Ionicons name="close" size={22} color={c.muted} />
                </Pressable>
              )}
            </View>
          </View>

          <View style={styles.body}>{children}</View>

          {footer ? (
            <View style={[styles.footer, { paddingBottom: bottomInset + space.xs }]}>{footer}</View>
          ) : (
            <View style={{ height: bottomInset }} />
          )}
        </View>
      </View>
    </Modal>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    // Full-screen overlay: dim backdrop fills it, the sheet is pinned to the bottom.
    root: { flex: 1, justifyContent: 'flex-end' },
    backdrop: { backgroundColor: 'rgba(0,0,0,0.35)' },
    sheet: {
      backgroundColor: c.background,
      borderTopLeftRadius: radius.lg,
      borderTopRightRadius: radius.lg,
      paddingHorizontal: space.md,
      paddingTop: space.xs,
    },
    handle: {
      alignSelf: 'center',
      width: 40,
      height: 4,
      borderRadius: radius.pill,
      backgroundColor: c.border,
      marginBottom: 10,
    },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space.sm, marginBottom: 4 },
    headerActions: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
    title: { ...type.title, color: c.text, flexShrink: 1 },
    action: { ...type.bodyLg, fontWeight: '700', color: c.primary },
    // flex:1 fills the space between the fixed header and pinned footer inside
    // the fixed-height sheet, so the body scrolls and the footer never overlaps.
    body: { flex: 1 },
    footer: { paddingTop: space.xs },
  });
