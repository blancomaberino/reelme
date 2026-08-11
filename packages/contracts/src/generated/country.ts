/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/country.json
 */
/**
 * One row of GET /api/v1/countries (T-110): an ISO 3166-1 alpha-2 code with its name in the request locale. The catalog is the single localized source of country names — the profile picker, the my-places filter chips and every `country_name` in a profile payload spell a country the same way because they all come from here.
 */
export interface Country {
  /**
   * ISO 3166-1 alpha-2, uppercase. The stored and exchanged value; `name` is display only.
   */
  code: string;
  /**
   * Localized display name (?locale= → Accept-Language → app default).
   */
  name: string;
}
