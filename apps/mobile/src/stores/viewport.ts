import { create } from 'zustand';

import type { Region } from '@/lib/geo';
import { clearSavedViewport, loadSavedViewport, saveViewport } from '@/lib/viewport';

// The remembered map viewport (T-100). Hydrated once at boot (alongside the
// settings store, see `_layout.tsx`) so the map screen can read it
// SYNCHRONOUSLY on mount — a returning user's map paints where they left it
// with no loading flash. Only a genuine first launch has to wait on anything.
type ViewportState = {
  /** The last settled viewport, or null when there is none yet. */
  saved: Region | null;
  /** False until `hydrate()` has resolved — distinguishes "no saved viewport"
   *  from "haven't looked yet", which the map screen must not confuse. */
  hydrated: boolean;
  /** Read the persisted viewport into memory. Safe to call more than once. */
  hydrate: () => Promise<void>;
  /** Record a settled viewport in memory + on disk (fire-and-forget write). */
  remember: (region: Region) => void;
  /**
   * Forget the remembered viewport, in memory and on disk. Called on sign-out:
   * a viewport is coarse location data, so the next person to sign in on a
   * shared device must not land on the previous user's last map position.
   * Stays `hydrated` — we HAVE looked, there is simply nothing saved now, and
   * flipping it back would hang the map's loading gate.
   */
  clear: () => Promise<void>;
};

export const useViewportStore = create<ViewportState>((set) => ({
  saved: null,
  hydrated: false,
  hydrate: async () => {
    const saved = await loadSavedViewport();
    set({ saved, hydrated: true });
  },
  remember: (region) => {
    set({ saved: region });
    saveViewport(region);
  },
  clear: async () => {
    set({ saved: null, hydrated: true });
    await clearSavedViewport();
  },
}));
