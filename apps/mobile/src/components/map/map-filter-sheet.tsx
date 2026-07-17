import { useMemo } from 'react';

import { usePaymentCards } from '@/api/hooks/usePaymentCards';
import { useMyPlacesTags, useTagCatalog } from '@/api/hooks/useTags';
import type { MapFilters } from '@/api/keys';
import { FilterGroup, FilterSheet, OptionPill } from '@/components/filters/filter-sheet';
import { TagAutocomplete } from '@/components/filters/tag-autocomplete';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';

/** Active map filters that surface as chips/badge (price + card + tags). */
export function mapFilterCount(f: MapFilters): number {
  return (f.price_range ? 1 : 0) + (f.card ? 1 : 0) + (f.tags?.length ?? 0);
}

/**
 * The map's filter sheet (T-032 §7): price tiers, payment cards with a discount
 * (T-079), and top tags. Toggling writes straight to the map store, so the map
 * refetches live behind the sheet. "Clear" resets only these facets — an active
 * saved-list scope (shown as the map banner) is preserved.
 */
export function MapFilterSheet({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const fmt = useFormat();
  const t = useT();
  const filters = useMapStore((s) => s.filters);
  const togglePrice = useMapStore((s) => s.togglePrice);
  const toggleCard = useMapStore((s) => s.toggleCard);
  const toggleTag = useMapStore((s) => s.toggleTag);
  const { data: cards } = usePaymentCards();

  // The map is the viewer's own places when authed (T-071) → filter by my tags;
  // a guest browses the public map → fall back to the global popular catalog.
  const authed = useSessionStore((s) => s.status === 'authed');
  const { data: myTags } = useMyPlacesTags({ enabled: authed });
  const { data: globalTags } = useTagCatalog({ enabled: !authed });
  const tagCatalog = useMemo(() => (authed ? (myTags ?? []) : (globalTags ?? [])), [authed, myTags, globalTags]);

  const activeTags = filters.tags ?? [];

  const clearFacets = () => {
    const s = useMapStore.getState();
    // Preserve list/filter scope; only drop the facet filters.
    s.setFilters({ ...s.filters, price_range: null, card: null, tags: [] });
  };

  return (
    <FilterSheet visible={visible} onClose={onClose} activeCount={mapFilterCount(filters)} onClear={clearFacets}>
      <FilterGroup label={t('filters.price')}>
        {[1, 2, 3, 4].map((tier) => (
          <OptionPill
            key={`price-${tier}`}
            label={fmt.price(tier)}
            selected={filters.price_range === tier}
            onPress={() => togglePrice(tier)}
          />
        ))}
      </FilterGroup>

      {(cards ?? []).length > 0 ? (
        <FilterGroup label={t('filters.cards')}>
          {(cards ?? []).map((card) => (
            <OptionPill
              key={`card-${card.card}`}
              label={`💳 ${card.card}`}
              selected={filters.card === card.card}
              onPress={() => toggleCard(card.card)}
            />
          ))}
        </FilterGroup>
      ) : null}

      <TagAutocomplete catalog={tagCatalog} selected={activeTags} onToggle={toggleTag} />
    </FilterSheet>
  );
}
