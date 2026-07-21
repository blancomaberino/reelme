// Auth-domain types (GET /me, auth responses). Hand-written: there is no JSON
// Schema for the auth payloads in packages/contracts yet, so these can't be
// contract-derived like the discovery shapes in ./places.ts / ./profile.ts
// (T-094). Add an `auth`/`me` schema to contracts to bring them under drift.

export type Me = {
  id: string;
  name: string;
  username: string;
  email: string;
  avatar_path: string | null;
  bio: string | null;
  birthdate: string | null;
  age: number | null;
  favorite_topics: string[];
  favorite_foods: string[];
  is_influencer: boolean;
  is_restaurant_owner: boolean;
  is_admin: boolean;
  is_public: boolean;
  preferred_analysis_model: string | null;
  stripe_connect_onboarded: boolean;
  email_verified_at: string | null;
  created_at: string | null;
};

export type AuthResponse = { token: string; user: Me };

export type FieldErrors = Record<string, string>;

/** Thrown by the response interceptor for 422s — carries per-field messages. */
export class ValidationError extends Error {
  constructor(
    public readonly fields: FieldErrors,
    message = 'Validation failed.',
  ) {
    super(message);
    this.name = 'ValidationError';
  }
}

/**
 * Thrown for a 403 `email_not_verified` (T-066) — a correct password on an
 * unconfirmed account. Carries the email so the screen can route to the verify
 * flow prefilled.
 */
export class EmailNotVerifiedError extends Error {
  constructor(public readonly email: string) {
    super('Email not verified.');
    this.name = 'EmailNotVerifiedError';
  }
}
