import { create } from 'zustand';

import type { MapFilters } from '@/api/keys';
import type { MapPin } from '@/api/places';

// Map-screen transient state (T-032). Kept out of React state so the MapView
// subtree never re-renders on selection/sheet changes; components subscribe
// with scoped selectors (e.g. `useMapStore((s) => s.filters)`).
type MapState = {
  filters: MapFilters;
  /** The pin backing the open bottom sheet, or null when dismissed. */
  selected: MapPin | null;
  setFilters: (filters: MapFilters) => void;
  toggleCuisine: (cuisine: string) => void;
  togglePrice: (price: number) => void;
  toggleTag: (tag: string) => void;
  clearFilters: () => void;
  select: (pin: MapPin | null) => void;
};

const EMPTY: MapFilters = { cuisine: null, price_range: null, tags: [] };

export const useMapStore = create<MapState>((set) => ({
  filters: EMPTY,
  selected: null,
  setFilters: (filters) => set({ filters }),
  toggleCuisine: (cuisine) =>
    set((s) => ({ filters: { ...s.filters, cuisine: s.filters.cuisine === cuisine ? null : cuisine } })),
  togglePrice: (price) =>
    set((s) => ({ filters: { ...s.filters, price_range: s.filters.price_range === price ? null : price } })),
  toggleTag: (tag) =>
    set((s) => {
      const tags = s.filters.tags ?? [];
      return {
        filters: { ...s.filters, tags: tags.includes(tag) ? tags.filter((t) => t !== tag) : [...tags, tag] },
      };
    }),
  clearFilters: () => set({ filters: EMPTY }),
  select: (selected) => set({ selected }),
}));
