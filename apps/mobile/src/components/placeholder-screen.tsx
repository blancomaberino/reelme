import { StyleSheet, Text, View } from 'react-native';

type Props = {
  title: string;
  subtitle?: string;
};

/** Minimal placeholder for M0 screen shells — real UIs land in later tasks. */
export function PlaceholderScreen({ title, subtitle }: Props) {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>{title}</Text>
      {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 8 },
  title: { fontSize: 24, fontWeight: '600' },
  subtitle: { fontSize: 15, opacity: 0.6, textAlign: 'center' },
});
