import type { PlaceEditSuggestion as ContractSuggestion } from '@reelmap/contracts';

/**
 * A proposed correction to a place's business info (T-083) — derived from the
 * schema (@reelmap/contracts) so a field rename fails typecheck rather than
 * rendering blank (T-094).
 */
export type PlaceEditSuggestion = ContractSuggestion;

/** One field's before/after inside a suggestion. */
export type SuggestionChange = ContractSuggestion['changes'][number];

/** Every column a suggestion may propose — the API's allow-list, verbatim. */
export type SuggestionField = SuggestionChange['field'];

/**
 * The subset the mobile form edits.
 *
 * Narrower than {@link SuggestionField} on purpose. `opening_hours_json` is
 * absent because the column carries two different shapes: enrichment writes
 * Google's `{periods, weekday_text}` object, which is what
 * `summarizeHours()` renders, while the curator form writes a list of rule
 * strings. A form that submitted lines would replace the first with the second
 * and the hours row would vanish from this very screen. The API still accepts
 * the field, so a curator can set it — the app just will not be what proposes
 * the shape it cannot read.
 *
 * `region`, `postal_code`, `cuisine_primary` and `price_range` are left out for
 * a plainer reason: the detail payload does not carry them, so the form could
 * not show what it was about to change.
 */
export type SuggestEditInput = {
  name?: string;
  address_line1?: string | null;
  city?: string | null;
  phone?: string | null;
  website?: string | null;
  /**
   * "Something else is wrong" (T-112) — free text for everything the fields
   * above cannot express.
   *
   * NOT a `SuggestionField`: it is not a column on `places` and never becomes
   * part of the diff. A submission carrying only this is valid; one carrying
   * neither it nor a field change is what the API refuses.
   */
  note?: string | null;
};

/** How long a note may be — the API's `PlaceEditSuggestion::NOTE_MAX`. */
export const NOTE_MAX_LENGTH = 2000;

/**
 * The form's fields, in the order they are shown — one list, so the field set,
 * the dirty check and the reset cannot drift apart.
 *
 * `satisfies readonly SuggestionField[]` is the compile-time proof that every
 * one of them is a field the API actually accepts. Without it a typo, or a
 * column the allow-list later drops, would typecheck here and 422 on device.
 */
export const SUGGEST_FIELDS = [
  'name',
  'address_line1',
  'city',
  'phone',
  'website',
] as const satisfies readonly SuggestionField[];

export type SuggestFormField = (typeof SUGGEST_FIELDS)[number];
