import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';

import { api } from '../client';

/**
 * Reporting content (T-049, 03 §2.16).
 *
 * Apple Guideline 1.2 and Google's UGC policy both require this to exist and to
 * be reachable — a reviewer looks for the control, not for the endpoint.
 */

/** Matches `App\Enums\ReportReason`. */
export const REPORT_REASONS = [
  'inappropriate',
  'spam',
  'wrong_place',
  'copyright',
  'fraud',
  'other',
] as const;

export type ReportReason = (typeof REPORT_REASONS)[number];

/**
 * Reporting a REVIEW is a different endpoint with a different vocabulary, and
 * both differences are deliberate on the server side.
 *
 * `POST /reviews/{id}/report` takes only a reason, validated against
 * `ReviewReport::REASONS` — and that list is genuinely not the general one:
 * `off_topic` is meaningful for a review and meaningless for a place, while
 * `wrong_place` and `copyright` are the reverse. Flattening them into one
 * vocabulary would put reasons in front of people that their target cannot
 * have.
 *
 * So the transport branches and the sheet stays single. Matches
 * `App\Models\ReviewReport::REASONS` exactly — a reason this list has and the
 * server does not is a 422 the user reads as "reporting is broken".
 */
export const REVIEW_REPORT_REASONS = ['spam', 'offensive', 'off_topic', 'other'] as const;

export type ReviewReportReason = (typeof REVIEW_REPORT_REASONS)[number];

/** Matches the server's morph aliases — never a class name. */
export type ReportableType = 'place' | 'share' | 'user' | 'source_post' | 'offer';

/** Every surface the one sheet can report, including the odd one out. */
export type ReportTargetType = ReportableType | 'review';

export type ReportInput = {
  reportable_type: ReportTargetType;
  reportable_id: string;
  reason: ReportReason | ReviewReportReason;
  details?: string;
};

/**
 * A 409 means "you already reported this", which is a SUCCESS from the user's
 * point of view — their flag is on file. Surfacing it as an error would tell
 * someone their report failed when it did not, and invite them to try again.
 */
export function isAlreadyReported(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 409;
}

export function useReport() {
  return useMutation({
    mutationFn: async (input: ReportInput): Promise<void> => {
      // Reviews have their own endpoint and take ONLY a reason — no
      // reportable_type, no details. Posting the general shape there is a 422.
      if (input.reportable_type === 'review') {
        await api.post(`/reviews/${encodeURIComponent(input.reportable_id)}/report`, {
          reason: input.reason,
        });

        return;
      }

      await api.post('/reports', input);
    },
    // Not retried: the endpoint is throttled and the unique constraint makes a
    // retry a guaranteed 409, so a retry can only turn a slow success into a
    // confusing one.
    retry: false,
  });
}
