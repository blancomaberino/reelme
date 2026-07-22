import { create } from 'zustand';

// Transient UI flags. `pendingShare` carries a link/text shared into Reelmap
// (T-025) across the auth gate: it's staged BEFORE any login redirect so an
// unauthenticated share survives sign-in and resumes on the ingest screen.
type PendingShare = { url: string; text: string };

type UiState = {
  rateLimited: boolean;
  pendingShare: PendingShare | null;
  // A deep-link from a notification tapped on a cold start (T-027): staged until
  // the auth gate resolves, then pushed — the navigator isn't mounted yet at the
  // moment the tap is read.
  pendingNotificationUrl: string | null;
  setRateLimited: (value: boolean) => void;
  setPendingShare: (share: PendingShare | null) => void;
  setPendingNotificationUrl: (url: string | null) => void;
};

export const useUiStore = create<UiState>((set) => ({
  rateLimited: false,
  pendingShare: null,
  pendingNotificationUrl: null,
  setRateLimited: (rateLimited) => set({ rateLimited }),
  setPendingShare: (pendingShare) => set({ pendingShare }),
  setPendingNotificationUrl: (pendingNotificationUrl) => set({ pendingNotificationUrl }),
}));
