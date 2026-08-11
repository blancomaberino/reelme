import type { Me } from '@/api/types';

/**
 * The authenticated user, with overrides merged on top.
 *
 * Ten suites each carried their own hand-written `Me` literal, so adding a
 * single field to the type broke all ten in the same way — which is how a
 * fixture ends up quietly diverging from the payload it is standing in for.
 * One builder, one place to add the next field.
 */
export function makeMe(overrides: Partial<Me> = {}): Me {
  return {
    id: '1',
    name: 'Ana',
    username: 'ana',
    email: 'ana@example.com',
    avatar_path: null,
    bio: null,
    birthdate: null,
    age: null,
    favorite_topics: [],
    favorite_foods: [],
    is_influencer: false,
    is_restaurant_owner: false,
    is_admin: false,
    is_public: true,
    country_code: null,
    country_name: null,
    preferred_analysis_model: null,
    stripe_connect_onboarded: false,
    email_verified_at: null,
    created_at: null,
    ...overrides,
  };
}
