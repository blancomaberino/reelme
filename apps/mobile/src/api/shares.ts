// Ingest-domain API types (POST/GET /shares). A share moves pending → fetching →
// analyzing → published | review | failed | rejected; `place` is populated once
// it publishes so the client can jump straight to the pin.
//
// The RESPONSE types are re-exported from @reelmap/contracts (T-102) — share.json
// is the single source of truth shared with ShareResource, so a renamed or removed
// API field breaks `tsc`, not the device. The types below that are NOT derived are
// request bodies and client-only view models; each says so.
import type {
  PendingVenue as ContractPendingVenue,
  ShareDetail as ContractShareDetail,
  SharePlace as ContractSharePlace,
  ShareStatus as ContractShareStatus,
  ReelmapExtraction,
} from '@reelmap/contracts';

/** One extracted venue — the unit the review form edits (review is single-place today). */
export type ExtractionPlace = ReelmapExtraction['places'][number];

export type ShareStatus = ContractShareStatus;

/** Statuses where the pipeline has stopped (success or otherwise). */
export const TERMINAL_STATUSES: ShareStatus[] = ['published', 'review', 'failed', 'rejected'];

export function isTerminal(status: ShareStatus): boolean {
  return TERMINAL_STATUSES.includes(status);
}

export type ShareFailure = NonNullable<ContractShareDetail['failure']>;

/**
 * CLIENT-ONLY. The pipeline writes `failure.code` as a free string at each stage
 * (there is no PHP enum, so the contract types it as `string`). These are the
 * values a client actually sees and branches copy on; anything else falls through
 * to the generic copy/action. A `review` share always carries a code too
 * (manual_fallback = true) — it explains WHY the pipeline paused for the user.
 */
export type FailureCode =
  | 'fetch_unavailable' // couldn't load the post → retry / add manually
  | 'fetch_auth_required' // private post → link account / add manually
  | 'geocode_failed' // extraction ok, couldn't place it → edit address / drop pin
  | 'media_too_large'
  | 'ffmpeg_error'
  | 'transcribe_error'
  | 'cost_cap_exceeded'
  | 'quota_exhausted'
  | 'invalid_model_output'
  | 'ollama_unreachable' // local model host unreachable — humanized share-side too
  | 'resolve_conflict';

/**
 * The API only lets a share be retried while it's `failed`, or `review` with a
 * transient fetch failure (ShareController::retry). Gating the button on the
 * same rule avoids a guaranteed 409/422.
 */
export function isRetryable(share: Pick<ShareDetail, 'status' | 'failure'>): boolean {
  if (share.status === 'failed') return true;
  return share.status === 'review' && share.failure?.code === 'fetch_unavailable';
}

/**
 * A share paused in `review` where the model produced an extraction but the
 * pipeline couldn't place/confirm it — the case the correction form is for. A
 * fetch failure (`fetch_*`) has no extraction yet, so those route to
 * retry/link-account instead of the editable form.
 */
export function hasEditableExtraction(share: Pick<ShareDetail, 'analysis'>): boolean {
  return !!share.analysis?.extraction?.places?.length;
}

/** The published pin (coordinates only — navigate by id, the place route accepts it). */
export type SharePlace = ContractSharePlace;

/**
 * GET /shares/{id} (ShareResource). `analysis.extraction` is the raw model output
 * conforming to the extraction contract: the review form (T-026) reads places[0],
 * per-field confidence and evidence out of it, and PATCH /shares/:id takes a
 * partial correction back.
 */
export type ShareDetail = ContractShareDetail;

/** A candidate place a pending venue can be attached to. */
export type PendingCandidate = ContractPendingVenue['candidates'][number];

/** An extracted venue that couldn't be auto-placed (T-071). */
export type PendingVenue = ContractPendingVenue;

/**
 * CLIENT-ONLY (composer state, not a response): what the composer collects —
 * a pasted link and/or a free-text caption.
 */
export type CreateShareInput = {
  url?: string;
  caption?: string;
  /**
   * How the share was initiated — the API records provenance and defaults it
   * ('share_sheet' when a URL is present, else 'manual'). The composer sends
   * 'paste_url'; the iOS/Android share sheet sends 'share_sheet'.
   */
  sharedVia?: 'paste_url' | 'share_sheet' | 'manual';
};

/**
 * CLIENT-ONLY VIEW MODEL. POST /shares returns a stripped 202 acknowledgement
 * (id + current status, `place` always null) — NOT a ShareResource, so it has no
 * schema; this flattens what the screen needs out of `data` + `meta`. Only the id
 * is used, to start polling GET /shares/{id} for the real, complete state.
 *
 * `idempotentReplay` mirrors `meta.idempotent_replay`: the API never returns a
 * 409 for a re-shared post — it replays the existing share (T-016). The screen
 * uses this to show a friendly "you already added this one" note instead of a
 * fresh "pinning…" flow.
 */
export type CreateShareResult = {
  id: string;
  status: ShareStatus;
  idempotentReplay: boolean;
};

/**
 * REQUEST BODY (no response schema): the corrections body for PATCH /shares/:id
 * (UpdateShareRequest), built from the extraction contract. `extraction`
 * is a PARTIAL, deep-merged onto the original run by ExtractionCorrector — send
 * only the changed leaves. `place_candidate.place_id` attaches to an existing
 * place from the offered dedupe candidates; `lat`/`lng` drop a manual pin.
 * `action: 'publish'` confirms and resumes the pipeline; omitting it just saves.
 */
export type ShareUpdatePayload = {
  extraction?: {
    places?: Partial<ExtractionPlace>[];
    influencer?: Partial<ReelmapExtraction['influencer']>;
  };
  place_candidate?: {
    place_id?: number | null;
    lat?: number | null;
    lng?: number | null;
  };
  action?: 'publish';
};

/**
 * Pull the first URL out of raw shared text. Instagram often shares a caption
 * like "Check this out! https://www.instagram.com/reel/… 😍" as plain text
 * rather than a clean URL, so the ingest flow extracts the link and treats the
 * rest as the caption. Trailing punctuation is trimmed; canonicalization is the
 * server's job (IngestShare), so this stays deliberately loose.
 */
export function extractUrl(text: string): string | null {
  const match = text.match(/https?:\/\/[^\s<>"']+/i);
  return match ? match[0].replace(/[.,)\]}>'"]+$/, '') : null;
}

/**
 * CLIENT-ONLY. Platforms Reelmap ingests, parsed client-side from a URL's
 * hostname for the composer badge — never read off a response.
 */
export type SharePlatform = 'instagram' | 'tiktok' | 'x' | 'youtube';

/**
 * Best-effort platform badge from a pasted/shared URL's hostname. Purely a UI
 * hint (the API re-derives the platform server-side from the resolved post);
 * returns null for anything unrecognized so the badge simply hides.
 */
export function platformFromUrl(url: string): SharePlatform | null {
  const match = url.trim().match(/^https?:\/\/([^/?#]+)/i);
  // Drop any port, then match the registrable domain or a subdomain of it — a
  // bare `endsWith('instagram.com')` would also match `notinstagram.com`.
  const host = match?.[1]?.toLowerCase().replace(/:\d+$/, '');
  if (!host) return null;
  const isHost = (domain: string) => host === domain || host.endsWith(`.${domain}`);
  if (isHost('instagram.com')) return 'instagram';
  if (isHost('tiktok.com')) return 'tiktok';
  if (isHost('x.com') || isHost('twitter.com')) return 'x';
  if (isHost('youtube.com') || host === 'youtu.be') return 'youtube';
  return null;
}
