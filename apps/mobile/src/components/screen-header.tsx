import { Ionicons } from '@expo/vector-icons';
import { type ReactNode, useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { useT } from '@/i18n';
import { safeBack } from '@/lib/nav';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

type Props = {
  title: string;
  /** Show the back chevron. Default true — a pushed screen almost always has one. */
  back?: boolean;
  /** Trailing action(s): an icon button, a "Done", a count. */
  right?: ReactNode;
  /** Hairline under the header. Off by default; on for scrolling content. */
  divided?: boolean;
  /**
   * Override the back action (a wizard step that goes back one page, not one
   * screen). Defaults to {@link safeBack}, never a bare `router.back()`.
   */
  onBack?: () => void;
};

/**
 * The back header, once (T-104).
 *
 * Fifteen screens hand-rolled this, and they had drifted: the title was 20pt on
 * five of them and 22pt on the rest, vertical padding 10 or 12, some kept a
 * spacer to centre the title and some did not, one carried the serif face and
 * the others the system one. None of that was a decision — it was fifteen
 * people-moments of typing a number.
 *
 * The title truncates rather than wrapping: a header that grows to two lines
 * shifts the content below it, and these titles are place names and usernames
 * that can be arbitrarily long.
 *
 * Back defaults to {@link safeBack}, NOT `router.back()`. Half these screens are
 * deep-link or push-notification targets with no stack to pop, where a bare
 * `back()` throws "GO_BACK was not handled by any navigator" and traps the user.
 * Three screens already imported safeBack by hand and three more inlined the
 * same ternary; making it the default is why the next one cannot get it wrong.
 */
export function ScreenHeader({ title, back = true, right, divided = false, onBack }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={[styles.header, divided && styles.divided]}>
      {back ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('common.back')}
          onPress={onBack ?? safeBack}
          hitSlop={12}
          style={styles.backTarget}
          testID="screen-header-back"
        >
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
      ) : null}

      <Text style={styles.title} numberOfLines={1} accessibilityRole="header">
        {title}
      </Text>

      {/* The trailing slot reserves the chevron's width even when empty, so a
          title reads as centred-ish rather than drifting right on screens with
          no action. */}
      {right ?? <View style={styles.spacer} />}
    </View>
  );
}

const BACK_WIDTH = 26;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      paddingHorizontal: space.md,
      paddingVertical: space.sm,
    },
    divided: {
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    backTarget: { borderRadius: radius.pill },
    title: { ...type.title, flex: 1, color: c.text },
    spacer: { width: BACK_WIDTH },
  });
