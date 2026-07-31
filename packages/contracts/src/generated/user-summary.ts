/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/user-summary.json
 */
/**
 * Compact public attribution block (03 §2.6) — the shape of UserSummaryResource, embedded wherever a sharer or list owner is credited. Only users who consented to public attribution are wrapped; a private user is represented as `null`, never as an anonymized stub.
 */
export interface UserSummary {
  id: string;
  username: string;
  name: string | null;
  avatar_path: string | null;
}
