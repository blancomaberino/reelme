import { Ionicons } from '@expo/vector-icons';
import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { reportError } from '@/lib/crash-reporting';
import { type MessageKey, useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

type Props = { children: ReactNode };
type State = { error: Error | null };

/**
 * Top-level error boundary (T-090). A thrown RENDER error (a bad prop shape, a
 * null deref in an inline .map()) would otherwise unmount the whole app to a red
 * box in dev / a blank screen in prod, invisible to us. This catches it, reports
 * it once via the (env-gated) crash reporter, and shows a branded "try again"
 * fallback whose reset re-mounts the subtree so a transient error recovers
 * without a full app restart. React Query already handles per-screen *data*
 * errors; this is the last-resort net for everything else.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Fires exactly once per caught error → one report, not a loop.
    reportError(error, { componentStack: info.componentStack ?? undefined });
  }

  reset = (): void => this.setState({ error: null });

  render(): ReactNode {
    if (this.state.error !== null) {
      return <ErrorFallback onReset={this.reset} />;
    }

    return this.props.children;
  }
}

function ErrorFallback({ onReset }: { onReset: () => void }) {
  const c = useColors();
  const t = useT() as (key: MessageKey) => string;
  const styles = makeStyles(c);

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.body}>
        <View style={styles.iconWrap}>
          <Ionicons name="alert-circle-outline" size={44} color={c.primary} />
        </View>
        <Text style={styles.title}>{t('errorBoundary.title')}</Text>
        <Text style={styles.message}>{t('errorBoundary.body')}</Text>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('errorBoundary.restart')}
          onPress={onReset}
          style={({ pressed }) => [styles.button, pressed && styles.buttonPressed]}
        >
          <Text style={styles.buttonText}>{t('errorBoundary.restart')}</Text>
        </Pressable>
      </View>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    body: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, paddingHorizontal: 32 },
    iconWrap: {
      width: 88,
      height: 88,
      borderRadius: 44,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primarySoft,
    },
    title: { fontSize: 22, fontWeight: '800', letterSpacing: -0.4, color: c.text, textAlign: 'center' },
    message: { fontSize: 15, lineHeight: 22, color: c.muted, textAlign: 'center' },
    button: {
      marginTop: 12,
      paddingHorizontal: 24,
      paddingVertical: 13,
      borderRadius: 14,
      backgroundColor: c.primary,
    },
    buttonPressed: { backgroundColor: c.primaryPressed },
    buttonText: { color: c.onPrimary, fontWeight: '700', fontSize: 16 },
  });
