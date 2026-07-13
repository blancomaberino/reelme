import { useMutation } from '@tanstack/react-query';

import { api } from '../client';
import type { CreateShareInput, CreateShareResult } from '../shares';

/**
 * Submit a pasted link and/or caption to the ingest pipeline (POST /shares).
 * Returns only the created share's id + status (the 202 body is a stripped
 * acknowledgement — `place` is always null there). The screen then drives
 * `useShareStatus(id)` to poll GET /shares/{id} for the real, complete state;
 * we deliberately do NOT seed the detail cache from this stripped payload.
 */
export function useCreateShare() {
  return useMutation({
    mutationFn: async (input: CreateShareInput): Promise<CreateShareResult> => {
      const { data } = await api.post<{ data: CreateShareResult }>('/shares', {
        url: input.url || undefined,
        caption: input.caption || undefined,
        shared_via: 'paste_url',
      });
      return data.data;
    },
  });
}
