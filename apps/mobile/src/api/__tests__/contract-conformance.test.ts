import type { PlaceSummary as ContractPlaceSummary, UserProfile } from '@reelmap/contracts';

import type { PlaceSummary } from '../places';
import type { PublicProfile } from '../profile';

/**
 * Contract-drift guard (T-094): the app's discovery types must stay identical to
 * the generated @reelmap/contracts types. These are COMPILE-TIME assertions —
 * `npx tsc --noEmit` (the mobile gate + CI) fails if a schema field is renamed,
 * removed, or retyped and the generated TS diverges from what the app codes to.
 * The `expect` below just makes this a runnable Jest file.
 */

// Bidirectional assignability = the two types are structurally identical.
type Exact<A, B> = [A] extends [B] ? ([B] extends [A] ? true : false) : false;

const placeSummaryMatchesContract: Exact<PlaceSummary, ContractPlaceSummary> = true;
const publicProfileMatchesContract: Exact<PublicProfile, UserProfile> = true;

// A realistic API row must satisfy the contract type verbatim — a renamed field
// (e.g. `country_code` → `country`) turns this into a tsc error, not a runtime one.
const fixture = {
  id: '1',
  name: 'Lanzhou Beef Noodle',
  slug: 'lanzhou-beef-noodle',
  status: 'active',
  lat: 51.5,
  lng: -0.13,
  category: 'ramen',
  price_range: 2,
  city: 'London',
  country_code: 'GB',
  thumbnail_url: null,
  mine: { share_id: '9', saved: false },
  source_count: 1,
  rating: { google: { value: 4.6, count: 80 } },
  distance_m: null,
  created_at: null,
} satisfies ContractPlaceSummary;

it('pins the mobile discovery types to @reelmap/contracts', () => {
  expect(placeSummaryMatchesContract).toBe(true);
  expect(publicProfileMatchesContract).toBe(true);
  expect(fixture.country_code).toBe('GB');
});
