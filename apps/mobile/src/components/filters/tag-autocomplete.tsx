import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import type { TagSummary } from '@/api/places';
import { useT } from '@/i18n';
import { foldSearch, haystackMatchIndex, tagHaystacks, tagLabelForSlug } from '@/lib/tags';
import { useFormat } from '@/lib/use-format';
import { type Palette, useColors } from '@/theme/colors';

/** Empty-state quick picks, and the cap on how many matches to show. */
const POPULAR_SUGGESTIONS = 8;
const MAX_SUGGESTIONS = 30;

type Props = {
  /**
   * Candidate tags to search over (already ordered by relevance). For the
   * filter this is the facet of the user's own places ({@link useMyPlacesTags});
   * for a guest it's the global popular catalog.
   */
  catalog: TagSummary[];
  /** Selected tag slugs (the filter value). */
  selected: string[];
  /** Add/remove a slug. */
  onToggle: (slug: string) => void;
};

/**
 * Tag filter as a typeahead instead of a wall of pills: selected tags show as
 * removable chips, and a search box filters the given tag {@link Props.catalog}.
 * Matching runs client-side over each tag's *localized* label (plus its raw
 * name/slug) so it is case-insensitive, accent-insensitive, and substring —
 * and, crucially, finds Spanish labels like "Informal" that the English-only
 * server can't.
 */
export function TagAutocomplete({ catalog, selected, onToggle }: Props) {
  const t = useT();
  const fmt = useFormat();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  const [q, setQ] = useState('');
  const typed = q.trim();
  const searching = typed.length > 0;

  // A tag's display label: server `label` if present, else locale-formatted name.
  const display = (tag: TagSummary) => tag.label ?? fmt.tag(tag.name);
  const labelFor = (slug: string) => tagLabelForSlug(catalog, slug, fmt.tag);

  const selectedSet = useMemo(() => new Set(selected), [selected]);

  // Precompute each tag's folded haystacks once per catalog/locale — not per
  // keystroke — so typing only folds the query and runs plain indexOf. Matching
  // uses the SAME label string we display, so search and label never disagree.
  const indexed = useMemo(
    () => catalog.map((tag) => ({ tag, hay: tagHaystacks(tag.label ?? fmt.tag(tag.name), tag.name, tag.slug) })),
    [catalog, fmt],
  );

  // Suggestions: while typing, every catalog tag that matches (ranked so
  // starts-with beats mid-word), else a few catalog tags — minus what's already
  // selected (those live in the chips row above).
  const suggestions = useMemo<TagSummary[]>(() => {
    const available = indexed.filter(({ tag }) => !selectedSet.has(tag.slug));
    if (!searching) return available.slice(0, POPULAR_SUGGESTIONS).map(({ tag }) => tag);
    const folded = foldSearch(typed);
    return available
      .map(({ tag, hay }) => ({ tag, at: haystackMatchIndex(hay, folded) }))
      .filter((m) => m.at !== -1)
      .sort((a, b) => a.at - b.at)
      .slice(0, MAX_SUGGESTIONS)
      .map((m) => m.tag);
  }, [indexed, selectedSet, searching, typed]);

  const noMatches = searching && suggestions.length === 0;

  return (
    <View style={styles.group}>
      <Text style={styles.groupLabel}>{t('filters.tags')}</Text>

      {selected.length > 0 ? (
        <View style={styles.chips}>
          {selected.map((slug) => (
            <Pressable
              key={slug}
              accessibilityRole="button"
              accessibilityLabel={t('filters.remove', { label: labelFor(slug) })}
              onPress={() => onToggle(slug)}
              style={({ pressed }) => [styles.chip, pressed && styles.pressed]}
            >
              <Text style={styles.chipLabel}>{labelFor(slug)}</Text>
              <Ionicons name="close" size={14} color={c.onPrimary} />
            </Pressable>
          ))}
        </View>
      ) : null}

      <View style={styles.inputWrap}>
        <Ionicons name="search" size={18} color={c.muted} />
        <TextInput
          style={styles.input}
          placeholder={t('filters.tagSearch')}
          placeholderTextColor={c.placeholder}
          value={q}
          onChangeText={setQ}
          autoCorrect={false}
          autoCapitalize="none"
          returnKeyType="search"
          accessibilityLabel={t('filters.tagSearch')}
        />
        {q.length > 0 ? (
          <Pressable accessibilityRole="button" accessibilityLabel={t('filters.tagClear')} onPress={() => setQ('')} hitSlop={8}>
            <Ionicons name="close-circle" size={18} color={c.placeholder} />
          </Pressable>
        ) : null}
      </View>

      {noMatches ? (
        <Text style={styles.empty}>{t('filters.tagNoResults', { query: typed })}</Text>
      ) : (
        <View style={styles.options}>
          {suggestions.map((tag) => (
            <Pressable
              key={tag.id}
              accessibilityRole="button"
              accessibilityLabel={display(tag)}
              onPress={() => onToggle(tag.slug)}
              style={({ pressed }) => [styles.suggestion, pressed && styles.pressed]}
            >
              <Ionicons name="add" size={14} color={c.primary} />
              <Text style={styles.suggestionLabel}>{display(tag)}</Text>
            </Pressable>
          ))}
        </View>
      )}
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    group: { marginBottom: 20 },
    groupLabel: {
      fontSize: 12,
      fontWeight: '700',
      letterSpacing: 0.5,
      textTransform: 'uppercase',
      color: c.muted,
      marginBottom: 12,
    },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 },
    // Selected chips are filled (accent) — they're the active filter value.
    chip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      paddingHorizontal: 14,
      paddingVertical: 9,
      borderRadius: 999,
      backgroundColor: c.primary,
    },
    chipLabel: { color: c.onPrimary, fontSize: 14, fontWeight: '600' },
    inputWrap: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      backgroundColor: c.surface,
      borderRadius: 12,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      paddingHorizontal: 12,
      height: 44,
    },
    input: { flex: 1, fontSize: 16, color: c.text },
    empty: { color: c.muted, fontSize: 14, paddingTop: 12 },
    options: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingTop: 12 },
    // Unselected suggestions read as "add" — outlined with a + glyph.
    suggestion: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 4,
      paddingLeft: 10,
      paddingRight: 14,
      paddingVertical: 9,
      borderRadius: 999,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    suggestionLabel: { color: c.text, fontSize: 14, fontWeight: '600' },
    pressed: { opacity: 0.6 },
  });
