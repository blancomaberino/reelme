import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';

import { useSettingsStore } from '@/stores/settings';

import { api } from '../client';
import type { Country } from '../countries';
import { queryKeys } from '../keys';

async function fetchCountries(): Promise<Country[]> {
  const { data } = await api.get<{ data: Country[] }>('/countries');
  return data.data;
}

/**
 * The country catalog, localized server-side (T-110).
 *
 * The app deliberately ships NO country dataset: names come from the API, which
 * resolves them through ICU for the request locale. That keeps one spelling of
 * "Türkiye" across the picker, the filter chips and every profile payload, and
 * means a third language needs no mobile release.
 *
 * Cached for the session and keyed by locale — the payload IS the localization,
 * so a language toggle must fetch rather than reuse the previous list.
 */
export function useCountries(opts?: { enabled?: boolean }) {
  const locale = useSettingsStore((s) => s.locale);
  return useQuery({
    queryKey: queryKeys.countries(locale),
    queryFn: fetchCountries,
    // A fixed list of 249 rows that only changes when ICU does.
    staleTime: 24 * 60 * 60_000,
    enabled: opts?.enabled ?? true,
  });
}

/**
 * `code → localized name` for labelling a country you already have the code for
 * (the my-places filter chips, a profile). Returns a lookup that falls back to
 * the code itself, so a chip renders "UY" rather than nothing while the catalog
 * is still loading or if the request failed.
 */
export function useCountryName(opts?: { enabled?: boolean }): (code: string | null | undefined) => string {
  const { data } = useCountries(opts);
  return useMemo(() => {
    const byCode = new Map((data ?? []).map((c) => [c.code, c.name]));
    return (code) => (code ? (byCode.get(code) ?? code) : '');
  }, [data]);
}
