/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place-edit-suggestion.json
 */
/**
 * One side of a proposed change. `array` is bounded to a list of STRINGS rather than left open (T-128): the only array-valued member of the `field` enum is `opening_hours_json`, which place.json pins as a flat string[]. An unbounded "array" here generates `unknown[]`, which is the same union-that-pins-nothing that hid the opening-hours bug on the other endpoint.
 */
export type SuggestedValue = string | number | string[] | null;

/**
 * A proposed correction to a place's business info (T-083): the response to POST /api/v1/places/{place}/suggestions, and one row of GET /api/v1/me/venues/suggestions. The submitter is deliberately absent — an operator judges the proposal, not the person who filed it.
 */
export interface PlaceEditSuggestion {
  id: string;
  place_id: string;
  /**
   * Present only where the row is listed across venues, so the screen can name the restaurant without a second request.
   */
  place?: {
    id: string;
    name: string;
    slug: string;
  };
  /**
   * A verified operator's own FIELD edit comes back `approved` — it applied on submit. Everything else is `pending` until a moderator decides, including an operator's own submission when it carries a note. `actioned` settles a note-only row: there was no patch to apply, and a human dealt with it by hand (T-112).
   */
  status: 'pending' | 'approved' | 'rejected' | 'actioned';
  /**
   * The row was the venue operator's own edit rather than a proposal awaiting review.
   */
  is_owner_submission: boolean;
  /**
   * One entry per changed field, in the allow-list's fixed order — a list rather than an object because JSON key order is not something a client may rely on, and both surfaces render these in order.
   */
  changes: {
    /**
     * Column name — the same vocabulary the submit endpoint accepts. The picture fields are absent by design: a suggestion may not propose an image URL.
     */
    field:
      | 'name'
      | 'address_line1'
      | 'address_line2'
      | 'city'
      | 'region'
      | 'postal_code'
      | 'country_code'
      | 'cuisine_primary'
      | 'price_range'
      | 'phone'
      | 'website'
      | 'opening_hours_json';
    /**
     * The value the submitter was looking at, captured at submit time. A reviewer compares it against the place as it is now to spot a proposal that has been overtaken.
     */
    from: SuggestedValue;
    to: SuggestedValue;
  }[];
  /**
   * Free text the submitter wrote for everything the field form cannot express — "this place closed down", "the pin is on the wrong side of the street" (T-112). A row may carry a note, a set of field changes, or both; a note-only row has an empty `changes`, so any surface rendering these must survive that.
   */
  note: string | null;
  created_at: string;
  reviewed_at: string | null;
}
