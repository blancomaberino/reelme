import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { ReviewSourceSummary } from '@/api/places';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { openWebUrl } from '@/lib/linking';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  sources: ReviewSourceSummary[];
};

/** Per-source display label (falls back to the raw id for an unknown source). */
function useSourceLabel() {
  const t = useT();

  return (source: string): string => {
    switch (source) {
      case 'native':
        return t('reviewSource.native');
      case 'google':
        return t('reviewSource.google');
      case 'trustpilot':
        return t('reviewSource.trustpilot');
      default:
        return source;
    }
  };
}

/**
 * The multi-source review aggregate (T-082): a compact "ratings across the web"
 * block — one row per resolving provider (Reelmap, Google, Trustpilot, …) with
 * its star rating, review count, last-synced note, and a deep link to read the
 * full reviews on that source. A source with a `url` is tappable; the intrinsic
 * native source (no url) is a plain row. Renders nothing when nothing resolved.
 */
export function ReviewSources({ sources }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const label = useSourceLabel();
  const styles = useMemo(() => makeStyles(c), [c]);

  if (sources.length === 0) return null;

  return (
    <View style={styles.block}>
      <Text style={styles.sectionTitle}>{t('place.reviewSources')}</Text>
      <View style={styles.list}>
        {sources.map((s) => {
          const name = label(s.source);
          const stars = s.rating != null ? Math.max(0, Math.min(5, Math.round(s.rating))) : 0;
          const count = t('reviewSource.count', { count: s.count });
          const synced = s.synced_at ? fmt.date(s.synced_at) : '';
          const row = (
            <>
              <View style={styles.rowMain}>
                <Text style={styles.source} numberOfLines={1}>
                  {name}
                </Text>
                <Text style={styles.stars}>
                  {'★'.repeat(stars)}
                  <Text style={styles.starsEmpty}>{'★'.repeat(5 - stars)}</Text>
                </Text>
              </View>
              <View style={styles.rowMeta}>
                <Text style={styles.rating}>
                  {s.rating != null ? s.rating.toFixed(1) : '—'}
                  <Text style={styles.count}>
                    {'  '}
                    {count}
                  </Text>
                </Text>
                {s.url ? (
                  <View style={styles.linkRow}>
                    <Text style={styles.link}>{t('reviewSource.read', { source: name })}</Text>
                    <Ionicons name="open-outline" size={13} color={c.primary} />
                  </View>
                ) : null}
              </View>
              {/* Freshness of an external cache (Google/Trustpilot); native has none. */}
              {synced ? <Text style={styles.synced}>{t('reviewSource.synced', { date: synced })}</Text> : null}
            </>
          );

          return s.url ? (
            <Pressable
              key={s.source}
              onPress={() => openWebUrl(s.url)}
              accessibilityRole="link"
              accessibilityLabel={`${name}, ${s.rating != null ? s.rating.toFixed(1) : '—'}, ${count}. ${t('reviewSource.read', { source: name })}`}
              style={({ pressed }) => [styles.card, pressed && styles.cardPressed]}
            >
              {row}
            </Pressable>
          ) : (
            <View key={s.source} style={styles.card}>
              {row}
            </View>
          );
        })}
      </View>
    </View>
  );
}

function makeStyles(c: Palette) {
  return StyleSheet.create({
    block: { gap: 10 },
    sectionTitle: {
      fontFamily: fonts.display,
      fontSize: 19,
      fontWeight: '700',
      color: c.text,
      letterSpacing: -0.2,
    },
    list: { gap: 8 },
    card: {
      backgroundColor: c.surface2,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: c.line2,
      paddingVertical: 10,
      paddingHorizontal: 12,
      gap: 4,
    },
    cardPressed: { opacity: 0.6 },
    rowMain: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
    source: { flex: 1, fontSize: 15, fontWeight: '700', color: c.text },
    stars: { fontSize: 13, color: c.gold, letterSpacing: 1 },
    starsEmpty: { color: c.line2 },
    rowMeta: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
    rating: { fontSize: 14, fontWeight: '600', color: c.text },
    count: { fontSize: 12, fontWeight: '400', color: c.ink2 },
    linkRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
    link: { fontSize: 13, color: c.primary, fontWeight: '600' },
    synced: { fontSize: 12, color: c.muted },
  });
}
