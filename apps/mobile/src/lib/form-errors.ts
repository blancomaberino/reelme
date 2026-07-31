import { NetworkError, ValidationError, type FieldErrors } from '@/api/types';
import type { MessageKey } from '@/i18n';

/**
 * Split a mutation error into per-field messages (from a 422 ValidationError)
 * and a single general message.
 *
 * The general message is a MessageKey, not a string, so the caller translates
 * it — a form that fails while offline has to say so in the user's language
 * (T-103), and "no connection" is a materially different instruction from
 * "something went wrong": one asks you to check your network, the other to try
 * again.
 */
export function formErrors(error: unknown): { fieldErrors: FieldErrors; generalError: MessageKey | null } {
  if (error instanceof ValidationError) {
    return { fieldErrors: error.fields, generalError: null };
  }
  if (error instanceof NetworkError) {
    return { fieldErrors: {}, generalError: 'common.error.offline' };
  }
  return {
    fieldErrors: {},
    generalError: error ? 'common.error.general' : null,
  };
}
