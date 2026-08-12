import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { FlatList, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import type { Country } from '@/api/countries';
import { useCountries } from '@/api/hooks/useCountries';
import { SheetShell } from '@/components/sheet-shell';
import { useT } from '@/i18n';
import { foldSearch, haystackMatchIndex } from '@/lib/search-text';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/** Stable empty catalog, so `indexed` does not re-run on every pre-load render. */
const NO_COUNTRIES: Country[] = [];

type Props = {
  visible: boolean;
  onClose: () => void;
  /** Currently selected ISO 3166-1 alpha-2 code, or null. */
  value: string | null;
  /**
   * The chosen country, or null to clear. The sheet closes itself after.
   *
   * The whole row, not just the code: the caller has to render the localized
   * name straight away, and handing back only `"PT"` would force it to re-look
   * up a name this component is already holding.
   */
  onSelect: (country: Country | null) => void;
};

/**
 * Searchable country picker (T-110).
 *
 * A list of 249 rows is not something anyone scrolls, so the search box is the
 * primary control and the list is what's left after it. Matching is
 * accent-insensitive over the LOCALIZED name the row actually renders
 * ({@link foldSearch}), so "turkiye" finds "Türkiye" and "espana" finds
 * "España" — plus the two-letter code, because someone who knows it will type
 * it. Prefix matches rank above mid-word ones, which is what keeps "Chile"
 * above "Chad"… and above every country whose name merely contains "chi".
 *
 * `FlatList`, not the sheet's ScrollView: 249 rows mounted at once is a visible
 * hitch on open, and it is why this sheet composes {@link SheetShell} directly
 * rather than reusing FilterSheet (a VirtualizedList cannot nest in a
 * ScrollView).
 *
 * The names come from the API — the app deliberately ships no country dataset,
 * so nothing here needs a release to spell a country differently or to add a
 * third language.
 */
export function CountryPicker({ visible, onClose, value, onSelect }: Props) {
  const t = useT();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  // Only fetch once the sheet has been opened — the catalog is 249 rows nobody
  // needs until they tap the field.
  const { data, isLoading, isError } = useCountries({ enabled: visible });
  const [q, setQ] = useState('');

  const countries = data ?? NO_COUNTRIES;

  // Fold each name once per catalog, not once per keystroke: typing then only
  // folds the query and runs indexOf.
  const indexed = useMemo(
    () => countries.map((country) => ({ country, hay: [foldSearch(country.name), foldSearch(country.code)] })),
    [countries],
  );

  const typed = q.trim();
  const results = useMemo<Country[]>(() => {
    if (!typed) return countries;
    const folded = foldSearch(typed);
    return indexed
      .map(({ country, hay }) => ({ country, at: haystackMatchIndex(hay, folded) }))
      .filter((m) => m.at !== -1)
      .sort((a, b) => a.at - b.at)
      .map((m) => m.country);
  }, [countries, indexed, typed]);

  // Dismissing clears the query too. The Modal unmounts the TextInput on close
  // while `q` lives out here and survives, so a backdrop tap left the next open
  // showing a stale search and a pre-filtered list with no visible cause.
  const dismiss = () => {
    setQ('');
    onClose();
  };

  const choose = (country: Country | null) => {
    onSelect(country);
    dismiss();
  };

  return (
    <SheetShell
      visible={visible}
      onClose={dismiss}
      title={t('country.pickerTitle')}
      // Clearing is a real answer ("I'd rather not say"), not an escape hatch —
      // so it sits in the header next to the title, never hidden in the list.
      action={value ? { label: t('country.clear'), onPress: () => choose(null) } : null}
    >
      <View style={styles.searchWrap}>
        <Ionicons name="search" size={18} color={c.muted} />
        <TextInput
          testID="country-search"
          style={styles.search}
          placeholder={t('country.searchPlaceholder')}
          placeholderTextColor={c.placeholder}
          value={q}
          onChangeText={setQ}
          autoCorrect={false}
          autoCapitalize="none"
          returnKeyType="search"
          accessibilityLabel={t('country.searchPlaceholder')}
        />
        {q.length > 0 ? (
          <Pressable accessibilityRole="button" accessibilityLabel={t('country.searchClear')} onPress={() => setQ('')} hitSlop={8}>
            <Ionicons name="close-circle" size={18} color={c.placeholder} />
          </Pressable>
        ) : null}
      </View>

      <FlatList
        data={results}
        keyExtractor={(country) => country.code}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="on-drag"
        // The sheet is a fixed 88% of the screen inside a Modal, which does not
        // resize for the keyboard — without this the last matching rows sit
        // behind it and cannot be scrolled clear. Same fix filter-sheet's
        // ScrollView already carries.
        automaticallyAdjustKeyboardInsets
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.list}
        ItemSeparatorComponent={() => <View style={styles.separator} />}
        ListEmptyComponent={
          <Text style={styles.empty}>
            {isLoading ? t('country.loading') : isError ? t('country.error') : t('country.noResults', { query: typed })}
          </Text>
        }
        renderItem={({ item }) => (
          <CountryRow country={item} selected={item.code === value} onPress={() => choose(item)} styles={styles} c={c} />
        )}
      />
    </SheetShell>
  );
}

function CountryRow({
  country,
  selected,
  onPress,
  styles,
  c,
}: {
  country: Country;
  selected: boolean;
  onPress: () => void;
  styles: ReturnType<typeof makeStyles>;
  c: Palette;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      accessibilityLabel={country.name}
      testID={`country-row-${country.code}`}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
    >
      <Text style={[styles.rowName, selected && styles.rowNameSelected]} numberOfLines={1}>
        {country.name}
      </Text>
      {/* The stored value, shown rather than hidden: the profile round-trips a
          code, and a letterspaced stamp also gives a 249-row list some rhythm. */}
      <Text style={styles.code}>{country.code}</Text>
      {selected ? <Ionicons name="checkmark" size={18} color={c.primary} /> : <View style={styles.checkSpacer} />}
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    searchWrap: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.xs,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.line2,
      borderRadius: radius.md,
      paddingHorizontal: space.sm,
      paddingVertical: space.xs,
      marginBottom: space.sm,
      // The same floor tag-autocomplete's search box carries: without it the
      // query crops at large OS text sizes, where the row is exactly the text.
      minHeight: 44,
    },
    search: { flex: 1, ...type.bodyLg, fontWeight: '400', color: c.text },
    list: { paddingBottom: space.md },
    row: { flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingVertical: space.sm },
    rowPressed: { opacity: 0.6 },
    rowName: { flex: 1, ...type.body, color: c.text },
    rowNameSelected: { color: c.primary, fontWeight: '700' },
    code: { ...type.caption, letterSpacing: 1, color: c.muted },
    // Keeps every row's name column the same width whether or not it's selected,
    // so the list doesn't shift as the selection moves.
    checkSpacer: { width: 18 },
    separator: { height: StyleSheet.hairlineWidth, backgroundColor: c.border },
    empty: { ...type.body, color: c.muted, paddingVertical: space.lg, textAlign: 'center' },
  });
