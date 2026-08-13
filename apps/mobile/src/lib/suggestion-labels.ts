import type { SuggestionField } from '@/api/suggestions';
import type { MessageKey } from '@/i18n';

/**
 * Field → label copy for a suggested place edit (T-083), over the API's FULL
 * allow-list.
 *
 * One map, shared by the suggest form and the operator's pending list, because
 * two maps over the same keys are two spellings waiting to happen — the form
 * saying "Calle" while the list beside it says "Dirección" is the kind of drift
 * nobody notices until a user asks which field they changed.
 *
 * `satisfies Record<SuggestionField, MessageKey>` rather than a template cast:
 * a cast only requires comparability, so adding a field with no copy would
 * typecheck and then render the literal key `suggest.field.region` to a user —
 * and the es/en parity test passes when a key is missing from BOTH.
 */
export const SUGGESTION_FIELD_LABEL = {
  name: 'suggest.field.name',
  address_line1: 'suggest.field.address',
  address_line2: 'suggest.field.address2',
  city: 'suggest.field.city',
  region: 'suggest.field.region',
  postal_code: 'suggest.field.postalCode',
  country_code: 'suggest.field.country',
  cuisine_primary: 'suggest.field.cuisine',
  price_range: 'suggest.field.priceRange',
  phone: 'suggest.field.phone',
  website: 'suggest.field.website',
  opening_hours_json: 'suggest.field.hours',
} satisfies Record<SuggestionField, MessageKey>;
