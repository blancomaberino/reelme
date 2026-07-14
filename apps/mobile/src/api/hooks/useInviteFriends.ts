import { useMutation } from '@tanstack/react-query';

import { api } from '../client';

/** Invite friends to Reelmap by email (T-069). */
export function useInviteFriends() {
  return useMutation({
    mutationFn: (emails: string[]) => api.post('/invites', { emails }),
  });
}
