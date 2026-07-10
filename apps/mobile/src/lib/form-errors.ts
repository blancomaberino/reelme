import { ValidationError, type FieldErrors } from '@/api/types';

/**
 * Split a mutation error into per-field messages (from a 422 ValidationError)
 * and a single general message (for everything else).
 */
export function formErrors(error: unknown): { fieldErrors: FieldErrors; generalError: string | null } {
  if (error instanceof ValidationError) {
    return { fieldErrors: error.fields, generalError: null };
  }
  return {
    fieldErrors: {},
    generalError: error ? 'Something went wrong. Please try again.' : null,
  };
}
