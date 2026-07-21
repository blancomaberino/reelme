import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { PendingCandidate } from '@/api/shares';
import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

/**
 * "Is this the same place?" dedupe picker for an ambiguous review. Choosing a
 * candidate attaches the share to that existing place (`place_candidate.place_id`
 * on PATCH); the "add as new" row (selectedId === null) keeps it a fresh pin.
 */
export function CandidatePicker({
  candidates,
  selectedId,
  onSelect,
}: {
  candidates: PendingCandidate[];
  /** Chosen existing place id, or null for "add as new". */
  selectedId: number | null;
  onSelect: (placeId: number | null) => void;
}) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  if (candidates.length === 0) return null;

  return (
    <View style={styles.wrap}>
      <Text style={styles.title}>{t('review.candidates.title')}</Text>
      <Text style={styles.hint}>{t('review.candidates.hint')}</Text>
      <View accessibilityRole="radiogroup" style={styles.rows}>
        {candidates.map((cand) => {
          const id = Number(cand.place_id);
          const on = selectedId === id;
          return (
            <Row
              key={cand.place_id}
              on={on}
              title={cand.name ?? t('share.pending.unnamed')}
              sub={
                cand.address ??
                (cand.distance_m != null ? t('review.candidate.distance', { meters: Math.round(cand.distance_m) }) : null)
              }
              onPress={() => onSelect(on ? null : id)}
              styles={styles}
              c={c}
            />
          );
        })}
        <Row
          on={selectedId === null}
          title={t('review.candidates.newPlace')}
          sub={null}
          isNew
          onPress={() => onSelect(null)}
          styles={styles}
          c={c}
        />
      </View>
    </View>
  );
}

function Row({
  on,
  title,
  sub,
  isNew,
  onPress,
  styles,
  c,
}: {
  on: boolean;
  title: string;
  sub: string | null;
  isNew?: boolean;
  onPress: () => void;
  styles: Styles;
  c: Palette;
}) {
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ selected: on }}
      accessibilityLabel={title}
      onPress={onPress}
      style={({ pressed }) => [styles.row, on && styles.rowOn, pressed && styles.rowPressed]}
    >
      <Ionicons
        name={on ? 'radio-button-on' : 'radio-button-off'}
        size={20}
        color={on ? c.primary : c.muted}
      />
      <View style={styles.rowBody}>
        <Text style={[styles.rowTitle, isNew && styles.rowNew]} numberOfLines={1}>
          {title}
        </Text>
        {sub ? (
          <Text style={styles.rowSub} numberOfLines={1}>
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
    wrap: { gap: 8 },
    title: { fontFamily: fonts.display, fontSize: 16, fontWeight: '700', color: c.text },
    hint: { fontSize: 13, color: c.muted, marginTop: -4 },
    rows: { gap: 8, marginTop: 2 },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      padding: 12,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: c.border,
      backgroundColor: c.surface,
    },
    rowOn: { borderColor: c.primary, backgroundColor: c.primarySoft },
    rowPressed: { opacity: 0.7 },
    rowBody: { flex: 1 },
    rowTitle: { fontSize: 15, fontWeight: '700', color: c.text },
    rowNew: { color: c.primary },
    rowSub: { fontSize: 13, color: c.muted },
  });
