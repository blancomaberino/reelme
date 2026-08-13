import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useVenueSuggestions } from '@/api/hooks/useSuggestEdit';
import type { PlaceEditSuggestion, SuggestionChange } from '@/api/suggestions';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { SUGGESTION_FIELD_LABEL } from '@/lib/suggestion-labels';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * What people are proposing about the venues you run (T-083).
 *
 * Read-only, and that is the design: a moderator decides these. A venue able to
 * approve edits to its own listing could also quietly refuse every correction to
 * it, which is the exact failure the queue exists to prevent. What an operator
 * gets instead is the thing they actually lack — visibility, plus the direct
 * edit on the place screen for anything they want to fix themselves.
 */
export default function RestaurantSuggestionsScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data, isLoading, isError, refetch } = useVenueSuggestions();
  const suggestions = data ?? [];

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('suggest.venue.title')} divided />

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} accessibilityLabel={t('common.loading')} />
      ) : isError ? (
        <View style={styles.empty}>
          <Ionicons name="cloud-offline-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('common.error.general')}</Text>
          <Button title={t('common.tryAgain')} variant="secondary" onPress={() => void refetch()} />
        </View>
      ) : suggestions.length === 0 ? (
        <View style={styles.empty}>
          <Ionicons name="checkmark-done-outline" size={40} color={c.muted} />
          <Text style={styles.emptyTitle}>{t('suggest.venue.empty.title')}</Text>
          <Text style={styles.emptyText}>{t('suggest.venue.empty.body')}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          <Text style={styles.intro}>{t('suggest.venue.intro')}</Text>
          {suggestions.map((suggestion) => (
            <SuggestionCard key={suggestion.id} suggestion={suggestion} styles={styles} c={c} />
          ))}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function SuggestionCard({
  suggestion,
  styles,
  c,
}: {
  suggestion: PlaceEditSuggestion;
  styles: Styles;
  c: Palette;
}) {
  const t = useT();
  const place = suggestion.place;

  return (
    <View style={styles.card} testID={`suggestion-${suggestion.id}`}>
      {/* The venue, tappable through to its page — an operator reading a
          proposed correction wants to look at what it is correcting. */}
      {place ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={place.name}
          onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: place.slug } })}
          style={({ pressed }) => [styles.cardHead, pressed && styles.pressed]}
        >
          <Text style={styles.venue} numberOfLines={1}>
            {place.name}
          </Text>
          <Ionicons name="chevron-forward" size={16} color={c.muted} />
        </Pressable>
      ) : null}

      {suggestion.changes.map((change) => (
        <ChangeRow key={change.field} change={change} styles={styles} />
      ))}

      {/* The free-text note (T-112). Not optional garnish: a note-only row has
          an EMPTY `changes`, so without this the operator gets a card with a
          venue name, "awaiting review", and no hint of what anyone said. */}
      {suggestion.note ? (
        <View style={styles.change} testID={`suggestion-note-${suggestion.id}`}>
          <Text style={styles.field}>{t('suggest.venue.note')}</Text>
          <Text style={styles.noteBody}>{suggestion.note}</Text>
        </View>
      ) : null}

      <Text style={styles.pending}>{t('suggest.venue.pending')}</Text>
    </View>
  );
}

/** One field's `from → to`, stacked so a long value wraps instead of truncating. */
function ChangeRow({ change, styles }: { change: SuggestionChange; styles: Styles }) {
  const t = useT();

  return (
    <View style={styles.change}>
      <Text style={styles.field}>{t(SUGGESTION_FIELD_LABEL[change.field])}</Text>
      <View style={styles.values}>
        <Text style={styles.from} numberOfLines={2}>
          {display(change.from) ?? t('suggest.venue.empty.value')}
        </Text>
        <Text style={styles.arrow}>→</Text>
        <Text style={styles.to} numberOfLines={2}>
          {display(change.to) ?? t('suggest.venue.empty.value')}
        </Text>
      </View>
    </View>
  );
}

/**
 * A proposed value as one readable line, or null when there is nothing to show.
 *
 * The union is the schema's: a string, a number (price range), an array
 * (opening hours) or null. Rendering an array straight into a `<Text>` would
 * print it comma-joined with no spaces; a plain `String(value)` on an object
 * would print "[object Object]" to a restaurant owner.
 */
function display(value: SuggestionChange['from']): string | null {
  if (value === null || value === '') return null;
  if (Array.isArray(value)) {
    const lines = value.filter((line): line is string => typeof line === 'string');
    return lines.length > 0 ? lines.join(' · ') : null;
  }
  return String(value);
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { marginTop: space.xxl },
    scroll: { padding: space.md, gap: space.sm },
    intro: { ...type.bodySm, color: c.muted, marginBottom: space.xxs },
    card: {
      backgroundColor: c.surface,
      borderRadius: radius.md,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: space.md,
      gap: space.sm,
    },
    cardHead: { flexDirection: 'row', alignItems: 'center', gap: space.xs },
    pressed: { opacity: 0.6 },
    venue: { ...type.title, flex: 1, color: c.text },
    change: { gap: space.xxs },
    field: { ...type.caption, color: c.muted, textTransform: 'uppercase', letterSpacing: 0.4 },
    // Wraps rather than scrolls: an address is longer than a phone number, and
    // a row that clipped it would hide the half being proposed.
    values: { flexDirection: 'row', alignItems: 'flex-start', flexWrap: 'wrap', gap: space.xs },
    // Somebody's own words, so they wrap in full rather than truncating the way
    // a field value does — the sentence IS the finding on a note-only row.
    noteBody: { ...type.body, color: c.text },
    from: { ...type.body, color: c.muted, textDecorationLine: 'line-through', flexShrink: 1 },
    arrow: { ...type.body, color: c.muted },
    to: { ...type.body, color: c.text, fontWeight: '600', flexShrink: 1 },
    pending: { ...type.caption, color: c.secondary },
    empty: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl, gap: space.sm },
    emptyTitle: { ...type.title, color: c.text, textAlign: 'center' },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },
  });
