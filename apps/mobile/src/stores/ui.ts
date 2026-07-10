import { create } from 'zustand';

// Transient UI flags. `pendingShareUrl` is used by the share-intent flow (T-025).
type UiState = {
  rateLimited: boolean;
  pendingShareUrl: string | null;
  setRateLimited: (value: boolean) => void;
  setPendingShareUrl: (url: string | null) => void;
};

export const useUiStore = create<UiState>((set) => ({
  rateLimited: false,
  pendingShareUrl: null,
  setRateLimited: (rateLimited) => set({ rateLimited }),
  setPendingShareUrl: (pendingShareUrl) => set({ pendingShareUrl }),
}));
