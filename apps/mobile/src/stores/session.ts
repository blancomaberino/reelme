import { create } from 'zustand';

import type { Me } from '@/api/types';

// Client state only: the hydrated user + auth status. The token is NOT here
// (SecureStore owns it); server data (the Me payload) is mirrored from the
// ['me'] query. Status starts `loading` until the root-layout gate resolves.
type SessionState = {
  user: Me | null;
  status: 'loading' | 'authed' | 'guest';
  setUser: (user: Me) => void;
  clear: () => void;
};

export const useSessionStore = create<SessionState>((set) => ({
  user: null,
  status: 'loading',
  setUser: (user) => set({ user, status: 'authed' }),
  clear: () => set({ user: null, status: 'guest' }),
}));
