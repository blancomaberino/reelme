// Ingest-domain API types (POST/GET /shares). Mirrors ShareResource on the API
// (app/Http/Resources/ShareResource.php). A share moves pending → fetching →
// analyzing → published | review | failed | rejected; `place` is populated once
// it publishes so the client can jump straight to the pin.

export type ShareStatus =
  | 'pending'
  | 'fetching'
  | 'analyzing'
  | 'review'
  | 'published'
  | 'failed'
  | 'rejected';

/** Statuses where the pipeline has stopped (success or otherwise). */
export const TERMINAL_STATUSES: ShareStatus[] = ['published', 'review', 'failed', 'rejected'];

export function isTerminal(status: ShareStatus): boolean {
  return TERMINAL_STATUSES.includes(status);
}

export type ShareFailure = {
  code: string;
  step: string | null;
  message: string;
  manual_fallback: boolean;
};

/** The published pin (coordinates only — navigate by id, the place route accepts it). */
export type SharePlace = {
  id: string;
  name: string;
  lat: number;
  lng: number;
};

export type ShareDetail = {
  id: string;
  status: ShareStatus;
  status_history: { status: ShareStatus; at: string | null }[];
  source_post: {
    id: string;
    platform: string;
    url: string | null;
    author_handle: string | null;
    caption: string | null;
    fetch_status: string;
  };
  analysis: {
    run_id: string;
    model: string | null;
    status: string;
    confidence: number | null;
    extraction: Record<string, unknown> | null;
  } | null;
  failure: ShareFailure | null;
  place: SharePlace | null;
};

/** What the composer collects — a pasted link and/or a free-text caption. */
export type CreateShareInput = {
  url?: string;
  caption?: string;
};

/**
 * POST /shares returns a stripped 202 acknowledgement (id + current status,
 * `place` always null) — NOT a full ShareResource. Only the id is used, to
 * start polling GET /shares/{id} for the real, complete state.
 */
export type CreateShareResult = {
  id: string;
  status: ShareStatus;
};
