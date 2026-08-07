import { type FailureCode } from '@/api/shares';
import type { MessageKey } from '@/i18n';

/**
 * The pipeline failure taxonomy, and the localized copy for each code (T-053).
 *
 * Lives here rather than on the status screen because there were TWO
 * implementations and only one was right: the status screen mapped the code to
 * `shares.fail.{code}.body`, while the composer's inline result and the map's
 * quick-share sheet rendered `failure.message` straight from the API — an
 * ENGLISH sentence in the middle of a Spanish screen ("This post is private.
 * Link the account or upload it manually."), because the server has no idea
 * what locale the device is in.
 *
 * Found by walking the flow on a device. Every unit test passed, because each
 * screen asserted on whatever it happened to render.
 *
 * `failure.message` is still worth having — in a log or a bug report. It is not
 * something to put in front of a user.
 */
export type FailAction = 'retry' | 'addManually' | 'aiSettings';

/**
 * `actions` are the buttons offered (retry only when the API honors it;
 * link-account is deferred per T-015, so private posts route to manual);
 * `stopStep` drives the stepper's error marker so un-run stages never render as
 * done. Typing this `Record<FailureCode, …>` forces an entry per code — a new
 * failure cannot be half-wired — and its keys double as the "this code has
 * dedicated copy" set.
 */
const FAILURE_TAXONOMY: Record<FailureCode, { actions: FailAction[]; stopStep: number }> = {
  fetch_unavailable: { actions: ['retry', 'addManually'], stopStep: 1 },
  fetch_auth_required: { actions: ['addManually'], stopStep: 1 },
  media_too_large: { actions: ['addManually'], stopStep: 1 },
  ffmpeg_error: { actions: ['retry'], stopStep: 1 },
  transcribe_error: { actions: ['retry'], stopStep: 2 },
  cost_cap_exceeded: { actions: [], stopStep: 2 },
  quota_exhausted: { actions: ['aiSettings'], stopStep: 2 },
  invalid_model_output: { actions: ['retry', 'aiSettings'], stopStep: 2 },
  ollama_unreachable: { actions: ['retry', 'aiSettings'], stopStep: 2 },
  geocode_failed: { actions: ['addManually'], stopStep: 3 },
  resolve_conflict: { actions: ['retry'], stopStep: 3 },
};

/** The taxonomy entry for a raw `failure.code`, or null for an unrecognized code. */
export const failureEntry = (code: string | undefined): { actions: FailAction[]; stopStep: number } | null =>
  code !== undefined && code in FAILURE_TAXONOMY ? FAILURE_TAXONOMY[code as FailureCode] : null;

/** i18n key for a failure's title; the generic pair for an unlisted code. */
export const failureTitleKey = (code: string | undefined): MessageKey =>
  (failureEntry(code) ? `shares.fail.${code}.title` : 'shares.fail.default.title') as MessageKey;

/** i18n key for a failure's explanatory body. */
export const failureBodyKey = (code: string | undefined): MessageKey =>
  (failureEntry(code) ? `shares.fail.${code}.body` : 'shares.fail.default.body') as MessageKey;
