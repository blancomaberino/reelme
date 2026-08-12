import type { Country as CountryContract } from '@reelmap/contracts';

/**
 * One row of GET /countries — derived from the schema (@reelmap/contracts
 * Country) so a field rename fails typecheck rather than rendering blank
 * (T-094).
 */
export type Country = CountryContract;
