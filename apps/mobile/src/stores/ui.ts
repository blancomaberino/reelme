import { create } from 'zustand';

// Transient UI flags. `pendingShare` carries a link/text shared into Reelmap
// (T-025) across the auth gate: it's staged BEFORE any login redirect so an
// unauthenticated share survives sign-in and resumes on the ingest screen.
type PendingShare = { url: string; text: string };

type UiState = {
  rateLimited: boolean;
  pendingShare: PendingShare | null;
  setRateLimited: (value: boolean) => void;
  setPendingShare: (share: PendingShare | null) => void;
};

export const useUiStore = create<UiState>((set) => ({
  rateLimited: false,
  pendingShare: null,
  setRateLimited: (rateLimited) => set({ rateLimited }),
  setPendingShare: (pendingShare) => set({ pendingShare }),
}));
