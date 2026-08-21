import type { OpeningHours } from '@/api/places';


/**
 * The lines to show for a place's opening hours (T-033, fixed in T-128).
 *
 * The input is a FLAT LIST OF HUMAN-READABLE STRINGS — what every API writer
 * stores and what `packages/contracts/schemas/place.json` pins: Google
 * `weekday_text` lines ("Monday: 9:00 AM – 11:00 PM"), schema.org rules
 * ("Mo-Fr 09:00-17:00"), or whatever a curator typed. It is prose, in the
 * SOURCE's wording and language — not a machine-readable structure.
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
 * judge in the source's own words. When the API serves structured periods WITH
 * a timezone, compute it here — do not reintroduce text parsing.
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
