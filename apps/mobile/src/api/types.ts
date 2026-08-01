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

/**
 * `/auth/login` answers one of two shapes (T-068): a full session, or — when
 * the account has a second factor — a challenge to exchange at
 * `/auth/two-factor-challenge`. Modelled as a union rather than optional fields
 * so a caller cannot read `token` without first narrowing.
 */
export type AuthResponse = { token: string; user: Me };

export type TwoFactorChallenge = { two_factor_required: true; challenge_token: string };

export type LoginResponse = AuthResponse | TwoFactorChallenge;

export function isTwoFactorChallenge(res: LoginResponse): res is TwoFactorChallenge {
  return 'two_factor_required' in res && res.two_factor_required === true;
}

/**
 * Thrown when the API asks for a second factor that this build cannot present
 * yet — the mobile 2FA screens are still to come (T-068 mobile half).
 *
 * Explicitly NOT silent: without this, `onAuthenticated` would destructure a
 * response that has no `token`, write `undefined` into secure storage and the
 * session store, and leave the app in a signed-in-looking state with no
 * credentials.
 */
export class TwoFactorRequiredError extends Error {
  constructor(public readonly challengeToken: string) {
    super('Two-factor authentication is required.');
    this.name = 'TwoFactorRequiredError';
  }
}

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
 * Thrown when a request never reached the API — no response at all: airplane
 * mode, a dead tunnel, DNS failure, a timeout (T-103). Distinct from a 5xx,
 * which DID reach the server. Screens use it to say "you're offline" instead of
 * the generic "something went wrong", and mutations use it to fail loudly
 * rather than sit paused waiting for a connection that may never come back.
 */
export class NetworkError extends Error {
  constructor(message = 'Network request failed.') {
    super(message);
    this.name = 'NetworkError';
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
