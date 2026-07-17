import { useMemo, useState } from 'react';

import { useMapTagCatalog } from '@/api/hooks/useTags';
import { type AppliedChip, FilterTriggerBar } from '@/components/filters/filter-trigger-bar';
import { MapFilterSheet, mapFilterCount } from '@/components/map/map-filter-sheet';
import { tagLabelForSlug } from '@/lib/tags';
import { useFormat } from '@/lib/use-format';
import { useMapStore } from '@/stores/map';

/**
 * Map filter entry point (T-032 §7): a compact "Filtros" button + the applied
 * filters as removable chips, opening the full {@link MapFilterSheet}. The map
 * is the viewer's own places (T-071 personal model), so these narrow that set.
 * Active filters live in the map store and feed the query key, so toggling one
 * refetches. Rendered above the MapView; it does not subscribe the MapView
 * subtree to changes.
 */
export function FilterBar() {
  const fmt = useFormat();
  const [open, setOpen] = useState(false);
  const filters = useMapStore((s) => s.filters);
  const togglePrice = useMapStore((s) => s.togglePrice);
  const toggleCard = useMapStore((s) => s.toggleCard);
  const toggleTag = useMapStore((s) => s.toggleTag);
  // Same catalog the sheet selects from, so a chip's label always resolves
  // (not just for tags in the global top-N).
  const catalog = useMapTagCatalog();

  // Applied filters, in a stable order: price → card → tags.
  const chips = useMemo<AppliedChip[]>(() => {
    const out: AppliedChip[] = [];
    if (filters.price_range) {
      const tier = filters.price_range;
      out.push({ key: 'price', label: fmt.price(tier), onRemove: () => togglePrice(tier) });
    }
    if (filters.card) {
      const card = filters.card;
      out.push({ key: 'card', label: `💳 ${card}`, onRemove: () => toggleCard(card) });
    }
    for (const slug of filters.tags ?? []) {
      out.push({ key: `tag-${slug}`, label: tagLabelForSlug(catalog, slug, fmt.tag), onRemove: () => toggleTag(slug) });
    }
    return out;
  }, [filters.price_range, filters.card, filters.tags, catalog, fmt, togglePrice, toggleCard, toggleTag]);

  return (
    <>
      <FilterTriggerBar elevated count={mapFilterCount(filters)} chips={chips} onOpen={() => setOpen(true)} />
      <MapFilterSheet visible={open} onClose={() => setOpen(false)} />
    </>
  );
}
