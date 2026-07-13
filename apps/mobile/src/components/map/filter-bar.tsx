import { useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text } from 'react-native';

import { usePopularTags } from '@/api/hooks/useTags';
import { priceGlyphs } from '@/lib/format';
import { useMapStore } from '@/stores/map';
import { type Palette, useColors } from '@/theme/colors';

/**
 * Horizontal filter chips over the map (T-032 §7): price tiers, top tags, and a
 * disabled "Following" chip (M3). Active filters live in the map store and feed
 * the query key, so toggling one refetches. Rendered above the MapView; it does
 * not subscribe the MapView subtree to changes.
 */
export function FilterBar() {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const filters = useMapStore((s) => s.filters);
  const togglePrice = useMapStore((s) => s.togglePrice);
  const toggleTag = useMapStore((s) => s.toggleTag);
  const { data: tags } = usePopularTags();

  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={styles.row}
      keyboardShouldPersistTaps="handled"
    >
      {[1, 2, 3, 4].map((tier) => (
        <FilterChip
          key={`price-${tier}`}
          label={priceGlyphs(tier)}
          active={filters.price_range === tier}
          onPress={() => togglePrice(tier)}
          styles={styles}
        />
      ))}

      {(tags ?? []).map((tag) => (
        <FilterChip
          key={tag.id}
          label={tag.name}
          active={(filters.tags ?? []).includes(tag.slug)}
          onPress={() => toggleTag(tag.slug)}
          styles={styles}
        />
      ))}

      <FilterChip label="Following" active={false} disabled onPress={() => {}} styles={styles} />
    </ScrollView>
  );
}

function FilterChip({
  label,
  active,
  disabled,
  onPress,
  styles,
}: {
  label: string;
  active: boolean;
  disabled?: boolean;
  onPress: () => void;
  styles: Styles;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected: active, disabled: !!disabled }}
      accessibilityLabel={disabled ? `${label} (coming soon)` : label}
      disabled={disabled}
      onPress={onPress}
      style={[styles.chip, active && styles.chipActive, disabled && styles.chipDisabled]}
    >
      <Text style={[styles.label, active && styles.labelActive]}>{label}</Text>
    </Pressable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    row: { gap: 8, paddingHorizontal: 12, paddingVertical: 8, alignItems: 'center' },
    chip: {
      paddingHorizontal: 14,
      paddingVertical: 8,
      borderRadius: 999,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      // A slight shadow so chips read above the map.
      shadowColor: '#000',
      shadowOpacity: 0.12,
      shadowRadius: 4,
      shadowOffset: { width: 0, height: 1 },
      elevation: 2,
    },
    chipActive: { backgroundColor: c.primary, borderColor: c.primary },
    chipDisabled: { opacity: 0.45 },
    label: { color: c.text, fontSize: 13, fontWeight: '600' },
    labelActive: { color: c.onPrimary },
  });
