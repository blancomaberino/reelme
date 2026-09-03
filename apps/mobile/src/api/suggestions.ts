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
 * Narrower than {@link SuggestionField} on purpose.
 *
 * `opening_hours_json` used to be excluded on the grounds that the column
 * carried two rival shapes — Google's `{periods, weekday_text}` object from
 * enrichment versus a list of rule strings from the curator form — so a
 * submitted list would have blanked the hours row. That was never true: every
 * writer stores a flat `string[]`, the contract now pins it as one (T-128), and
 * `hourLines()` hands those lines to the screen verbatim. The shape objection is gone.
 *
 * It stays out for a smaller, real reason: this form is a
 * `Record<field, string>` of single-line text fields, and hours are a
 * list of up to fourteen lines the API caps individually (`max:14`,
 * `max:120` per line). Editing that needs a multi-line editor, a lines↔array
 * mapping, and client-side limits mirroring those rules, or a curator gets a
 * 422 with no idea which line was too long — a feature with its own design
 * pass, not a side effect of fixing the payload shape. Until then the API still
 * accepts the field, so a moderator or operator can set it in Filament, and the
 * note's placeholder names wrong hours as something to report — T-128 is what
 * put hours on the screen to be noticed, so leaving no way to flag them would
 * have been the gap that feature opened.
 *
 * `region`, `postal_code`, `cuisine_primary` and `price_range` are left out for
 * a different reason: the detail payload does not carry them, so the form could
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
