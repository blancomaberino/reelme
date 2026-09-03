/**
 * Opening-hour fixtures, shared by the `hourLines` unit test and the place
 * detail screen test (T-128).
 *
 * Shared rather than copy-pasted for one specific reason: these lines are
 * valuable precisely because their BYTES are surprising, and the two copies
 * this replaces had already diverged in how they escaped them. A fixture whose
 * point is byte-exactness cannot live in two places.
 *
 * Extraction is safe here — normally a shared fixture risks one edit silently
 * weakening two tests at once, but `hourLines`'s test asserts the U+2009/U+202F
 * on the FUNCTION'S OUTPUT, so a normalizing edit to this file still turns it
 * red rather than quietly agreeing with itself.
 */

/**
 * `la-diecisiete-brasas-y-afines-ekaubv`, OBSERVED — dumped byte-for-byte from
 * the dev database, all seven rows, not retyped.
 *
 * Written with `\u` escapes on purpose: Google separates the times with THIN
 * SPACE (U+2009) around the EN DASH and NARROW NO-BREAK SPACE (U+202F) before
 * AM/PM, so this line holds six characters that look like a space and are not
 * one. As literals they are invisible in an editor and unreviewable in a diff,
 * and any "tidy the whitespace" pass would silently normalize them into a shape
 * the API has never sent — which is this task's bug, one layer down.
 *
 * What one real row contains, and why each part defeats a parser: a "Closed"
 * day; two windows in a row, the second crossing midnight; an opening time with
 * no meridiem of its own ("12:00 – 4:00 PM" means 12:00 PM); and ENGLISH day
 * names, while this app's default locale is Spanish — the language is the
 * SOURCE's, and nothing in the payload says which it used.
 *
 * Deliberately NOT in alphabetical order (Monday…Sunday), so a `.sort()`
 * slipped anywhere into the pipeline fails an order assertion. A Mon/Tue/Wed
 * excerpt is already sorted and would pin nothing.
 */
export const LA_DIECISIETE = [
  'Monday: Closed',
  'Tuesday: 12:00\u2009–\u20094:00\u202fPM, 8:00\u202fPM\u2009–\u200912:00\u202fAM',
  'Wednesday: 12:00\u2009–\u20094:00\u202fPM, 8:00\u202fPM\u2009–\u200912:00\u202fAM',
  'Thursday: 12:00\u2009–\u20094:00\u202fPM, 8:00\u202fPM\u2009–\u200912:00\u202fAM',
  'Friday: 12:00\u2009–\u20094:00\u202fPM, 8:00\u202fPM\u2009–\u20091:00\u202fAM',
  'Saturday: 12:00\u2009–\u20094:00\u202fPM, 8:00\u202fPM\u2009–\u20091:00\u202fAM',
  'Sunday: 12:00\u2009–\u20094:30\u202fPM',
];

/** `clara-cafe-x3ojjv`, OBSERVED — a second real row shape. */
export const CLARA_CAFE = [
  'Monday: Closed',
  'Tuesday: 8:30\u202fAM\u2009–\u20098:00\u202fPM',
  'Sunday: 11:00\u202fAM\u2009–\u20097:00\u202fPM',
];

/**
 * schema.org rules. PLAUSIBLE, NOT OBSERVED — no place in the dev database has
 * website-sourced hours yet. `WebsiteBusinessSource` has two branches:
 * `openingHours` passes a rule string through verbatim, while
 * `openingHoursSpecification` builds "Monday, Tuesday 09:00–17:00". Neither
 * carries the day-name-and-colon prefix Google's lines do.
 */
export const SCHEMA_ORG = ['Mo-Fr 09:00-17:00', 'Sa,Su 10:00-14:00'];
export const SCHEMA_ORG_SPEC = ['Monday, Tuesday 09:00–17:00', 'Saturday 10:00–14:00'];

/**
 * A source that answered in Spanish. PLAUSIBLE, NOT OBSERVED — all seven places
 * we hold answered in English, which is itself the point. The repeated
 * "Cerrado" rows are load-bearing: keyed by text they would collide and one
 * would disappear.
 */
export const SPANISH = [
  'lunes: Cerrado',
  'martes: 12:00 – 16:00, 20:00 – 00:00',
  'Cerrado',
  'Cerrado',
];
