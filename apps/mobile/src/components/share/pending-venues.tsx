import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { usePendingVenue } from '@/api/hooks/usePendingVenue';
import type { PendingVenue } from '@/api/shares';
import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

/**
 * The still-pending venues on a partially-published multi-place share (T-071).
 * Each venue offers its candidate places to attach (resolve) or a dismiss — the
 * write side of the T-013 partial-publish gap. Owner-only surface.
 */
export function PendingVenues({ shareId, venues }: { shareId: string; venues: PendingVenue[] }) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { resolve, dismiss } = usePendingVenue(shareId);
  const busy = resolve.isPending || dismiss.isPending;

  if (venues.length === 0) return null;

  return (
    <View style={styles.wrap}>
      <Text style={styles.heading}>{t('share.pending.heading', { count: venues.length })}</Text>
      {venues.map((venue) => (
        <View key={venue.index} style={styles.venue}>
          <View style={styles.venueHead}>
            <Text style={styles.venueName} numberOfLines={1}>
              {venue.name ?? t('share.pending.unnamed')}
            </Text>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('share.pending.dismiss')}
              hitSlop={8}
              disabled={busy}
              onPress={() => dismiss.mutate(venue.index)}
            >
              <Text style={styles.dismiss}>{t('share.pending.dismiss')}</Text>
            </Pressable>
          </View>

          {venue.candidates.length === 0 ? (
            <Text style={styles.noCandidates}>{t('share.pending.noCandidates')}</Text>
          ) : (
            <View style={styles.candidates}>
              {venue.candidates.map((cand) => (
                <Candidate
                  key={cand.place_id}
                  label={cand.name ?? t('share.pending.unnamed')}
                  sub={cand.address}
                  disabled={busy}
                  onPress={() => resolve.mutate({ index: venue.index, placeId: cand.place_id })}
                  styles={styles}
                  c={c}
                />
              ))}
            </View>
          )}
        </View>
      ))}
    </View>
  );
}

function Candidate({
  label,
  sub,
  disabled,
  onPress,
  styles,
  c,
}: {
  label: string;
  sub: string | null;
  disabled: boolean;
  onPress: () => void;
  styles: Styles;
  c: Palette;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [styles.candidate, pressed && styles.candidatePressed]}
    >
      <Ionicons name="add-circle-outline" size={18} color={c.primary} />
      <View style={styles.candidateBody}>
        <Text style={styles.candidateName} numberOfLines={1}>
          {label}
        </Text>
        {sub ? (
          <Text style={styles.candidateSub} numberOfLines={1}>
            {sub}
          </Text>
        ) : null}
      </View>
    </Pressable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { alignSelf: 'stretch', gap: 10, marginTop: 8 },
    heading: { fontFamily: fonts.display, fontSize: 15, fontWeight: '700', color: c.text },
    venue: {
      gap: 8,
      padding: 12,
      backgroundColor: c.surface,
      borderRadius: 14,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    venueHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
    venueName: { flex: 1, fontSize: 15, fontWeight: '700', color: c.text },
    dismiss: { fontSize: 13, fontWeight: '700', color: c.muted },
    noCandidates: { fontSize: 13, color: c.muted },
    candidates: { gap: 6 },
    candidate: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingVertical: 8,
      paddingHorizontal: 10,
      borderRadius: 10,
      backgroundColor: c.primarySoft,
    },
    candidatePressed: { opacity: 0.6 },
    candidateBody: { flex: 1 },
    candidateName: { fontSize: 14, fontWeight: '700', color: c.text },
    candidateSub: { fontSize: 12, color: c.muted },
  });
