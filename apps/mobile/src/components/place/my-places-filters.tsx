import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text } from 'react-native';

import { usePopularTags } from '@/api/hooks/useTags';
import type { MyPlacesFilters as Filters } from '@/api/keys';
import type { PlaceSummary } from '@/api/places';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { type Palette, useColors } from '@/theme/colors';

type Props = {
  /** The loaded rows — country/type facets are derived from what's actually here. */
  places: PlaceSummary[];
  filters: Filters;
  onChange: (patch: Partial<Filters>) => void;
};

/** Distinct, sorted, non-null values of a field across the loaded places. */
function distinct(places: PlaceSummary[], pick: (p: PlaceSummary) => string | null): string[] {
  return [...new Set(places.map(pick).filter((v): v is string => !!v))].sort();
}

/**
 * The "my places" facet bar (T-071): a sort toggle plus country / type / tag
 * chips. Country and type chips are derived from the loaded collection (only
 * facets you actually have appear); the active value is always kept in view so
 * a server-filtered set that collapses to one value can still be cleared.
 */
export function MyPlacesFilters({ places, filters, onChange }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: tags } = usePopularTags();

  const countries = useMemo(() => {
    const set = distinct(places, (p) => p.country_code);
    if (filters.country && !set.includes(filters.country)) set.unshift(filters.country);
    return set;
  }, [places, filters.country]);

  const types = useMemo(() => {
    const set = distinct(places, (p) => p.category);
    if (filters.type && !set.includes(filters.type)) set.unshift(filters.type);
    return set;
  }, [places, filters.type]);

  const sort = filters.sort ?? 'recent';
  const activeTags = filters.tags ?? [];

  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      style={styles.scroll}
      contentContainerStyle={styles.row}
      keyboardShouldPersistTaps="handled"
    >
      <Chip
        label={sort === 'popular' ? t('myPlaces.sort.popular') : t('myPlaces.sort.recent')}
        active
        icon="swap-vertical"
        styles={styles}
        onPress={() => onChange({ sort: sort === 'popular' ? 'recent' : 'popular' })}
      />

      {countries.map((code) => (
        <Chip
          key={`country-${code}`}
          label={code}
          active={filters.country === code}
          styles={styles}
          onPress={() => onChange({ country: filters.country === code ? null : code })}
        />
      ))}

      {types.map((type) => (
        <Chip
          key={`type-${type}`}
          label={fmt.tag(type)}
          active={filters.type === type}
          styles={styles}
          onPress={() => onChange({ type: filters.type === type ? null : type })}
        />
      ))}

      {(tags ?? []).map((tag) => (
        <Chip
          key={tag.id}
          label={fmt.tag(tag.name)}
          active={activeTags.includes(tag.slug)}
          styles={styles}
          onPress={() =>
            onChange({
              tags: activeTags.includes(tag.slug)
                ? activeTags.filter((s) => s !== tag.slug)
                : [...activeTags, tag.slug],
            })
          }
        />
      ))}
    </ScrollView>
  );
}

function Chip({
  label,
  active,
  icon,
  onPress,
  styles,
}: {
  label: string;
  active: boolean;
  icon?: keyof typeof Ionicons.glyphMap;
  onPress: () => void;
  styles: Styles;
}) {
  const c = useColors();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected: active }}
      accessibilityLabel={label}
      onPress={onPress}
      style={[styles.chip, active && styles.chipActive]}
    >
      {icon ? <Ionicons name={icon} size={13} color={active ? c.onPrimary : c.text} /> : null}
      <Text style={[styles.label, active && styles.labelActive]}>{label}</Text>
    </Pressable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    // A horizontal ScrollView in a flex-column parent otherwise stretches to fill
    // the vertical space (and centers its chips in that band); flexGrow:0 makes it
    // hug the chip row so the list sits directly beneath it.
    scroll: { flexGrow: 0 },
    row: { gap: 8, paddingHorizontal: 16, paddingBottom: 10, alignItems: 'center' },
    chip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 4,
      paddingHorizontal: 14,
      paddingVertical: 8,
      borderRadius: 999,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    chipActive: { backgroundColor: c.primary, borderColor: c.primary },
    label: { color: c.text, fontSize: 13, fontWeight: '600' },
    labelActive: { color: c.onPrimary },
  });
