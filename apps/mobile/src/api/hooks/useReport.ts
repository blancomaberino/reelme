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

/** Matches the server's morph aliases — never a class name. */
export type ReportableType = 'place' | 'share' | 'user' | 'source_post' | 'offer';

export type ReportInput = {
  reportable_type: ReportableType;
  reportable_id: string;
  reason: ReportReason;
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
      await api.post('/reports', input);
    },
    // Not retried: the endpoint is throttled and the unique constraint makes a
    // retry a guaranteed 409, so a retry can only turn a slow success into a
    // confusing one.
    retry: false,
  });
}
