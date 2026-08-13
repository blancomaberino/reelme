import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useSuggestEdit } from '@/api/hooks/useSuggestEdit';
import type { PlaceDetail } from '@/api/places';
import {
  NOTE_MAX_LENGTH,
  SUGGEST_FIELDS,
  type SuggestEditInput,
  type SuggestFormField,
} from '@/api/suggestions';
import { Button } from '@/components/button';
import { SheetShell } from '@/components/sheet-shell';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { SUGGESTION_FIELD_LABEL } from '@/lib/suggestion-labels';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * The five place fields plus the free-text note (T-112).
 *
 * `note` rides in the same state object rather than a `useState` of its own so
 * that the seed/reset below cannot forget it — a note left behind on re-open is
 * the same bug as a stale phone number, and one that only shows up when someone
 * abandons the sheet and comes back.
 */
type Form = Record<SuggestFormField | 'note', string>;

type Props = {
  visible: boolean;
  onClose: () => void;
  place: PlaceDetail;
  /** Called after a proposal was QUEUED, so the screen can say thank you. */
  onQueued: () => void;
};

/**
 * Correct a place's business info (T-083).
 *
 * One component, two framings, because they are one form. A verified operator
 * (`place.can_edit`) is editing their own listing and it applies on save;
 * everybody else is proposing a correction that a moderator reads first. Only
 * the title, the note and the button label differ — building a second "owner
 * edit" screen would have been two forms to keep in step, and the one that
 * drifted would be the operator's, which is the one used least in testing and
 * most in production.
 *
 * The save button stays disabled until something actually differs from the
 * place as shown. The API refuses an empty diff with a 422 either way; doing it
 * here as well means nobody meets that error by tapping the only button on the
 * sheet.
 *
 * Opening hours are deliberately not editable here — see `SUGGEST_FIELDS`.
 */
export function SuggestEditSheet({ visible, onClose, place, onQueued }: Props) {
  const t = useT();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const suggest = useSuggestEdit(place.slug);
  const owner = place.can_edit === true;

  const initial = useMemo<Form>(
    () => ({
      name: place.name ?? '',
      address_line1: place.address_line1 ?? '',
      city: place.city ?? '',
      phone: place.phone ?? '',
      website: place.website ?? '',
      // Always blank: the note is a message, not a value being corrected. There
      // is nothing on the place for it to be seeded FROM, and a previous
      // submission's words must not reappear pre-filled.
      note: '',
    }),
    [place.name, place.address_line1, place.city, place.phone, place.website],
  );

  const [form, setForm] = useState<Form>(initial);
  // What the form was last seeded from, or null while the sheet is closed.
  const [seed, setSeed] = useState<Form | null>(visible ? initial : null);

  // Re-seed on the OPEN transition, and whenever the place changes underneath
  // an open sheet (an operator's own save lands back here through the query
  // cache). `Modal` unmounts its children on close but this state is owned
  // outside it, so without this a re-open shows the last abandoned edit.
  //
  // Adjusted during render rather than in an effect: React re-runs this
  // component before touching the screen, so the fields never paint stale for a
  // frame — and an effect that setState()s is the cascading-render pattern the
  // React Compiler lint (rightly) refuses.
  if (visible && seed !== initial) {
    setSeed(initial);
    setForm(initial);
  } else if (!visible && seed !== null) {
    // Forget the seed on close, so re-opening re-seeds even when the place has
    // not changed — the case the whole guard exists for.
    setSeed(null);
  }

  const patch = useMemo<SuggestEditInput>(() => {
    const out: Record<string, string | null> = {};
    for (const field of SUGGEST_FIELDS) {
      const next = form[field].trim();
      if (next === initial[field].trim()) continue;
      // An emptied optional field clears it; `name` is NOT NULL in the schema,
      // so a blank one is simply not a change worth sending — the button is
      // disabled for it below rather than letting the API 422.
      out[field] = next === '' ? null : next;
    }
    // Only when they wrote something. Trimmed here as well as on the API, so
    // whitespace never enables the button for a submission the server refuses.
    const note = form.note.trim();
    if (note !== '') out.note = note;

    return out as SuggestEditInput;
  }, [form, initial]);

  const nameEmptied = form.name.trim() === '';
  // A note alone is a complete proposal — the whole point of T-112 — so it
  // counts toward `dirty` exactly like a field change. `nameEmptied` still
  // blocks: a blank required name is a form the API would refuse, note or not.
  const dirty = Object.keys(patch).length > 0 && !nameEmptied;

  const submit = () => {
    if (!dirty) return;
    suggest.mutate(patch, {
      onSuccess: (suggestion) => {
        onClose();
        // Only the queued path needs a receipt. An operator's edit is its own
        // confirmation — the screen behind the sheet already shows the change.
        //
        // Driven by the status the API returned rather than by `owner`, which is
        // what makes it right for T-112 with no change: an operator who ALSO
        // wrote a note gets `pending` back, because a note queues no matter who
        // sent it — and they see the receipt, which is the truth.
        if (suggestion.status !== 'approved') onQueued();
      },
    });
  };

  return (
    <SheetShell
      visible={visible}
      onClose={onClose}
      title={owner ? t('suggest.title.owner') : t('suggest.title')}
      // Save is disabled until something changes, so it is not a way out — a
      // sheet opened and read would otherwise offer a dead button, an unlabelled
      // backdrop and nothing else.
      showClose
      footer={
        <Button
          title={owner ? t('suggest.save') : t('suggest.submit')}
          onPress={submit}
          disabled={!dirty || suggest.isPending}
          loading={suggest.isPending}
          testID="suggest-submit"
        />
      }
    >
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        keyboardVerticalOffset={space.lg}
        style={styles.fill}
      >
        <ScrollView
          contentContainerStyle={styles.body}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          {/* What happens when they tap save, said BEFORE they tap it. The two
              outcomes are genuinely different — one is published, one is read
              by a stranger first — and a form that looks identical in both
              cases is a form that surprises somebody. */}
          <View style={[styles.note, owner ? styles.noteOwner : styles.noteReview]}>
            <Ionicons
              name={owner ? 'shield-checkmark-outline' : 'time-outline'}
              size={17}
              color={owner ? c.green : c.secondary}
            />
            <Text style={[styles.noteText, owner ? styles.noteTextOwner : styles.noteTextReview]}>
              {owner ? t('suggest.note.owner') : t('suggest.note.review')}
            </Text>
          </View>

          {SUGGEST_FIELDS.map((field) => (
            <TextField
              key={field}
              label={t(SUGGESTION_FIELD_LABEL[field])}
              value={form[field]}
              onChangeText={(value) => setForm((prev) => ({ ...prev, [field]: value }))}
              placeholder={t('suggest.placeholder.empty')}
              testID={`suggest-${field}`}
              {...INPUT_PROPS[field]}
            />
          ))}

          {/* The "something else is wrong" box (T-112), last because it is the
              fallback: everything the five fields above cannot say — "this
              closed down", "the photo is of another restaurant". It goes to the
              SUGGESTION queue, not to reports; the flag control on the screen
              behind stays the abuse channel, and the two must keep reading
              differently. */}
          <TextField
            label={t('suggest.field.note')}
            value={form.note}
            onChangeText={(value) => setForm((prev) => ({ ...prev, note: value }))}
            placeholder={t('suggest.field.notePlaceholder')}
            testID="suggest-note"
            multiline
            numberOfLines={4}
            maxLength={NOTE_MAX_LENGTH}
            autoCapitalize="sentences"
            // Left-aligned text in a tall box: RN centres multiline content
            // vertically on Android, which reads as a mis-rendered field.
            textAlignVertical="top"
            style={styles.noteInput}
          />
          <Text style={styles.hint}>{t('suggest.field.noteHint')}</Text>

          {suggest.isError ? <Text style={styles.error}>{t('common.error.general')}</Text> : null}

          {/* Bottom breathing room: the last field must clear the pinned footer
              when the keyboard is up. */}
          <View style={styles.tail} />
        </ScrollView>
      </KeyboardAvoidingView>
    </SheetShell>
  );
}

/**
 * Per-field keyboard behaviour. A phone field that opens a QWERTY keyboard, or
 * a venue name auto-lowercased by `TextField`'s default `autoCapitalize="none"`
 * (right for email and handles, wrong for a proper noun), is the difference
 * between a form people finish and one they abandon.
 */
const INPUT_PROPS = {
  name: { autoCapitalize: 'words' },
  address_line1: { autoCapitalize: 'words' },
  city: { autoCapitalize: 'words' },
  phone: { keyboardType: 'phone-pad' },
  website: { keyboardType: 'url', autoCorrect: false },
} as const satisfies Record<SuggestFormField, object>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    fill: { flex: 1 },
    body: { paddingTop: space.sm, gap: space.sm },
    note: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: space.xs,
      padding: space.sm,
      borderRadius: radius.md,
      marginBottom: space.xxs,
    },
    noteOwner: { backgroundColor: c.greenSoft },
    noteReview: { backgroundColor: c.secondarySoft },
    noteText: { ...type.bodySm, flex: 1, lineHeight: 18 },
    noteTextOwner: { color: c.green },
    noteTextReview: { color: c.secondary },
    // Tall enough to read as "write a few sentences" rather than as another
    // one-line field that happens to wrap.
    noteInput: { minHeight: 96, paddingTop: space.sm },
    hint: { ...type.caption, color: c.muted, marginTop: -space.xxs },
    error: { ...type.bodySm, color: c.danger },
    tail: { height: space.xl },
  });
