import { create } from 'zustand';

// Transient UI flags. `pendingShare` carries a link/text shared into Reelmap
// (T-025) across the auth gate: it's staged BEFORE any login redirect so an
// unauthenticated share survives sign-in and resumes on the ingest screen.
export type PendingShare = { url: string; text: string };

type UiState = {
  rateLimited: boolean;
  /**
   * Mirrors React Query's `onlineManager` (T-103), fed by NetInfo in
   * `lib/network.ts`. Drives the connection banner; the query layer reads
   * `onlineManager` itself, not this flag.
   */
  offline: boolean;
  pendingShare: PendingShare | null;
  // A deep-link from a notification tapped on a cold start (T-027): staged until
  // the auth gate resolves, then pushed — the navigator isn't mounted yet at the
  // moment the tap is read.
  pendingNotificationUrl: string | null;
  setRateLimited: (value: boolean) => void;
  setOffline: (value: boolean) => void;
  setPendingShare: (share: PendingShare | null) => void;
  setPendingNotificationUrl: (url: string | null) => void;
};

export const useUiStore = create<UiState>((set) => ({
  rateLimited: false,
  offline: false,
  pendingShare: null,
  pendingNotificationUrl: null,
  setRateLimited: (rateLimited) => set({ rateLimited }),
  setOffline: (offline) => set({ offline }),
  setPendingShare: (pendingShare) => set({ pendingShare }),
  setPendingNotificationUrl: (pendingNotificationUrl) => set({ pendingNotificationUrl }),
}));
