import { useMemo, useState } from 'react';

import { useMyPlacesTags } from '@/api/hooks/useTags';
import type { MyPlacesFilters as Filters } from '@/api/keys';
import type { PlaceSummary } from '@/api/places';
import { FilterGroup, FilterSheet, OptionPill } from '@/components/filters/filter-sheet';
import { type AppliedChip, FilterTriggerBar } from '@/components/filters/filter-trigger-bar';
import { TagAutocomplete } from '@/components/filters/tag-autocomplete';
import { useT } from '@/i18n';
import { tagDisplayName } from '@/lib/tags';
import { useFormat } from '@/lib/use-format';

type Props = {
  /** The loaded rows — country/type facets are derived from what's actually here. */
  places: PlaceSummary[];
  filters: Filters;
  onChange: (patch: Partial<Filters>) => void;
};

/** Distinct, sorted, non-null values of a field across the loaded places. */
function distinct(places: PlaceSummary[], pick: (p: PlaceSummary) => string | null): string[] {
  return [...new Set(places.map(pick).filter((v): v is string => !!v))].sort();
}

/** Active facet filters (country + type + tags); sort is always set, so excluded. */
function facetCount(f: Filters): number {
  return (f.country ? 1 : 0) + (f.type ? 1 : 0) + (f.tags?.length ?? 0);
}

/**
 * The "my places" filter entry point (T-071): a compact "Filtros" button + the
 * applied facets as removable chips, opening a sheet with sort / country / type
 * / tag groups. Country and type options are derived from the loaded collection
 * (only facets you actually have appear); an active value is always kept in view
 * so a server-filtered set that collapses to one value can still be cleared.
 */
export function MyPlacesFilters({ places, filters, onChange }: Props) {
  const t = useT();
  const fmt = useFormat();
  const [open, setOpen] = useState(false);
  // The tags actually on my places — the filter's candidate set + chip labels.
  const { data: myTags } = useMyPlacesTags();
  const tags = useMemo(() => myTags ?? [], [myTags]);

  const countries = useMemo(() => {
    const set = distinct(places, (p) => p.country_code);
    if (filters.country && !set.includes(filters.country)) set.unshift(filters.country);
    return set;
  }, [places, filters.country]);

  const types = useMemo(() => {
    const set = distinct(places, (p) => p.category);
    if (filters.type && !set.includes(filters.type)) set.unshift(filters.type);
    return set;
  }, [places, filters.type]);

  const sort = filters.sort ?? 'recent';
  const activeTags = useMemo(() => filters.tags ?? [], [filters.tags]);

  // Applied facets as removable chips: country → type → tags.
  const chips = useMemo<AppliedChip[]>(() => {
    const out: AppliedChip[] = [];
    if (filters.country) out.push({ key: 'country', label: filters.country, onRemove: () => onChange({ country: null }) });
    if (filters.type) out.push({ key: 'type', label: fmt.tag(filters.type), onRemove: () => onChange({ type: null }) });
    for (const slug of activeTags) {
      out.push({
        key: `tag-${slug}`,
        label: fmt.tag(tagDisplayName(tags, slug)),
        onRemove: () => onChange({ tags: activeTags.filter((s) => s !== slug) }),
      });
    }
    return out;
  }, [filters.country, filters.type, activeTags, tags, fmt, onChange]);

  const toggleTag = (slug: string) =>
    onChange({ tags: activeTags.includes(slug) ? activeTags.filter((s) => s !== slug) : [...activeTags, slug] });

  return (
    <>
      <FilterTriggerBar count={facetCount(filters)} chips={chips} onOpen={() => setOpen(true)} />

      <FilterSheet
        visible={open}
        onClose={() => setOpen(false)}
        activeCount={facetCount(filters)}
        onClear={() => onChange({ country: null, type: null, tags: [] })}
      >
        <FilterGroup label={t('filters.sort')}>
          <OptionPill
            label={t('myPlaces.sort.recent')}
            icon="swap-vertical"
            selected={sort === 'recent'}
            onPress={() => onChange({ sort: 'recent' })}
          />
          <OptionPill
            label={t('myPlaces.sort.popular')}
            icon="flame-outline"
            selected={sort === 'popular'}
            onPress={() => onChange({ sort: 'popular' })}
          />
        </FilterGroup>

        {countries.length > 0 ? (
          <FilterGroup label={t('filters.country')}>
            {countries.map((code) => (
              <OptionPill
                key={`country-${code}`}
                label={code}
                selected={filters.country === code}
                onPress={() => onChange({ country: filters.country === code ? null : code })}
              />
            ))}
          </FilterGroup>
        ) : null}

        {types.length > 0 ? (
          <FilterGroup label={t('filters.cuisine')}>
            {types.map((type) => (
              <OptionPill
                key={`type-${type}`}
                label={fmt.tag(type)}
                selected={filters.type === type}
                onPress={() => onChange({ type: filters.type === type ? null : type })}
              />
            ))}
          </FilterGroup>
        ) : null}

        <TagAutocomplete catalog={tags} selected={activeTags} onToggle={toggleTag} />
      </FilterSheet>
    </>
  );
}
