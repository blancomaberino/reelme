import type { OpeningHours, OpenState } from '@/api/places';
import type { MessageKey } from '@/i18n/en';


/**
 * The lines to show for a place's opening hours (T-033, fixed in T-128).
 *
 * The input is a FLAT LIST OF HUMAN-READABLE STRINGS — what every API writer
 * stores and what `packages/contracts/schemas/place.json` pins. It now has TWO
 * origins, and this function treats them identically (T-168):
 *
 *  - **Generated**, when the place has structured periods: the API writes the
 *    week itself in the REQUEST'S locale — Spanish day names, the locale's first
 *    day of the week and its 12/24-hour clock, and a localized word for a closed
 *    day taken from the ABSENCE of an interval. A voseo-Spanish app showing
 *    "Monday: Closed" is what prompted it.
 *  - **The source's own prose** otherwise: Google `weekday_text`
 *    ("Monday: 9:00 AM – 11:00 PM"), a schema.org rule, or whatever a curator
 *    typed — in the source's wording, language and day order.
 *
 * Either way it is text to render, never a structure to read. The reasons below
 * are why the second kind must not be parsed; the first kind removes the need
 * to, because the API already did the reading.
 *
 * ## Why this returns only lines, and claims nothing about open-or-closed
 *
 * Deriving "Open now" from these lines would be a guess dressed as a fact, and
 * the failure mode is a person standing at a locked door. Every step of the
 * parse is ambiguous, and the dev database proves it rather than the docs:
 *
 *  - **Language is the source's, not the reader's.** Every place we hold has
 *    ENGLISH day names ("Monday: Closed") while the app's default locale is
 *    Spanish, because Google answered in `en`. Keying off day names, or
 *    re-sorting the week, is wrong the moment a source answers in another
 *    language — and there is no field saying which language it answered in.
 *  - **Which line is today.** `weekday_text` is ordered by the source locale's
 *    first day of week (Monday-first in most locales, Sunday-first in en-US),
 *    so index 0 is not a fixed weekday either. Picking "today's line" by
 *    position is the same wrong claim, made quietly.
 *  - **The meridiem is often omitted on the opening time.** Real rows read
 *    "12:00 – 4:00 PM" (i.e. 12:00 *PM*) and "8:30 AM – 8:00 PM". A plain
 *    HH:MM read gets the first kind wrong by twelve hours, which is precisely
 *    the error that reports a shut restaurant as open.
 *  - **A line is not one window, and need not be a window at all.** "Monday:
 *    Closed" is a real row; so is "Tuesday: 12:00 – 4:00 PM, 8:00 PM – 12:00 AM",
 *    two windows, the second crossing midnight. The separator is an EN DASH
 *    (U+2013), except where a source used a hyphen or the word "to".
 *  - **The whitespace is not whitespace.** Dumped from the dev database rather
 *    than assumed: Google separates the times with THIN SPACE (U+2009) around
 *    the en dash and NARROW NO-BREAK SPACE (U+202F) before AM/PM, so
 *    "Friday: 12:00 – 4:00 PM, 8:00 PM – 1:00 AM" holds six characters that
 *    look like a space and are not one. Splitting on `' - '` or `' '` returns
 *    the line unsplit, and it fails INVISIBLY — the source and the screen look
 *    identical to the eye. (This cost the T-128 Maestro flow a run before it
 *    was spotted, on a screen that was rendering perfectly.)
 *  - **Timezone.** Even a flawless parse yields the PLACE's local time, which
 *    the payload does not carry. Compared against the device clock it is wrong
 *    for every place outside the viewer's own zone.
 *
 * A wrong "Open now" is worse than no badge, so this claims nothing: it returns
 * only the lines, for the screen to render verbatim, letting the reader
 * judge in the source's own words. The API now serves a computed `open_state`
 * (T-155) — see {@link openStateLabel}. It is decided server-side from
 * structured periods and the venue's own IANA timezone, which is why the parse
 * warned against above never happened: nothing below reads these lines to
 * decide open or closed, and nothing should.
 *
 * Total: never throws. Tolerates `null`/`undefined`, an empty array, and
 * non-string or blank entries slipping through at runtime — the payload is
 * validated at the edge, but a response cached before the shape was pinned is
 * not (that stale object is exactly the T-128 bug).
 */
export function hourLines(hours: OpeningHours | null | undefined): string[] {
  if (!Array.isArray(hours)) return [];

  // Duplicates are kept on purpose — dropping a repeated line would silently
  // hide data the source did send. Callers must key rows by index, not by text.
  return hours
    .filter((line): line is string => typeof line === 'string')
    .map((line) => line.trim())
    .filter((line) => line.length > 0);
}

/**
 * How stale a payload may be before its open/closed cue is dropped. Minutes, not
 * hours: a venue can close inside this window, and the cost of being wrong is
 * someone standing at a locked door.
 */
export const OPEN_STATE_MAX_AGE_MS = 5 * 60 * 1000;

/**
 * The status cue for a place, or NULL when there is no honest one to show
 * (T-155).
 *
 * The API decides open-or-closed; this only chooses WHICH MESSAGE says so, and
 * returns its key rather than a rendered string — so the decision is testable
 * without asserting on Spanish. `open_state`
 * is null whenever the venue has no structured periods or no timezone, and null
 * MUST render as no cue — never "Cerrado". A confidently wrong "Closed" sends
 * someone away from a restaurant that is open and wanted their business, which
 * is the whole reason the previous summary was deleted in T-128.
 *
 * `closes_at` / `opens_at` are venue-local wall clocks the server already
 * formatted; they are interpolated, never re-parsed against the device clock,
 * which belongs to a different timezone than the venue in the case that matters.
 *
 * Total: never throws. A malformed cached object (one predating this field)
 * yields no cue rather than a wrong one.
 */
export function openStateLabel(
  state: OpenState | null | undefined,
  ageMs = 0,
): { key: MessageKey; vars?: { time: string }; open: boolean } | null {
  if (!state || typeof state.open_now !== 'boolean') return null;

  // THE CUE AGES OUT. `open_state` is a fact about the moment the API answered,
  // and the place query is persisted for 24h — so a cold start with no network
  // can paint an 11-hour-old payload saying "Abierto · cierra 23:30" at nine the
  // next morning, with the refetch never resolving. That is exactly the
  // confidently-wrong "open" this whole feature is built to avoid, arriving by a
  // route the server cannot close. Past the window the cue disappears and the
  // hours lines remain — the same honest degradation as a place with no
  // structured hours at all.
  if (ageMs > OPEN_STATE_MAX_AGE_MS) return null;

  const clock = (value: unknown): string | null =>
    typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(value) ? value : null;

  if (state.open_now) {
    const until = clock(state.closes_at);
    // A venue that never closes has no closing time — say "open", not "closes 00:00".
    return until
      ? { key: 'place.openUntil', vars: { time: until }, open: true }
      : { key: 'place.openNow', open: true };
  }

  const next = clock(state.opens_at);
  return next
    ? { key: 'place.closedUntil', vars: { time: next }, open: false }
    : { key: 'place.closedNow', open: false };
}
