import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import type { ReelmapExtraction } from '@reelmap/contracts';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

/**
 * The caption / transcript quotes the model leaned on, so the reviewer can see
 * WHERE a value came from before trusting it (04 §7). Keyframe thumbnails aren't
 * shown yet — the share payload carries only `frame_refs` indexes, not asset
 * URLs; surfacing the frames needs the API to expose keyframe media (deferred).
 */
export function EvidencePanel({ evidence }: { evidence: ReelmapExtraction['evidence'] | undefined }) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const caption = evidence?.caption_quotes ?? [];
  const transcript = evidence?.transcript_quotes ?? [];

  if (caption.length === 0 && transcript.length === 0) {
    return <Text style={styles.none}>{t('review.evidence.none')}</Text>;
  }

  return (
    <View style={styles.wrap}>
      {caption.length > 0 ? (
        <Group icon="chatbubble-ellipses-outline" label={t('review.evidence.caption')} quotes={caption} styles={styles} c={c} />
      ) : null}
      {transcript.length > 0 ? (
        <Group icon="mic-outline" label={t('review.evidence.transcript')} quotes={transcript} styles={styles} c={c} />
      ) : null}
    </View>
  );
}

function Group({
  icon,
  label,
  quotes,
  styles,
  c,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  quotes: string[];
  styles: Styles;
  c: Palette;
}) {
  return (
    <View style={styles.group}>
      <View style={styles.groupHead}>
        <Ionicons name={icon} size={13} color={c.muted} />
        <Text style={styles.groupLabel}>{label}</Text>
      </View>
      {quotes.map((q, i) => (
        <Text key={`${i}-${q.slice(0, 12)}`} style={styles.quote}>
          “{q}”
        </Text>
      ))}
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { gap: 14 },
    none: { fontSize: 13, color: c.muted, fontStyle: 'italic' },
    group: { gap: 6 },
    groupHead: { flexDirection: 'row', alignItems: 'center', gap: 5 },
    groupLabel: { fontSize: 12, fontWeight: '700', letterSpacing: 0.3, textTransform: 'uppercase', color: c.muted },
    // Italic sets the pulled quotes apart from the form's labels.
    quote: {
      fontStyle: 'italic',
      fontSize: 14,
      lineHeight: 20,
      color: c.ink2,
      paddingLeft: 10,
      borderLeftWidth: 2,
      borderLeftColor: c.line2,
    },
  });
