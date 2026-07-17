import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { TagSummary } from '../places';

async function fetchPopularTags(limit: number): Promise<TagSummary[]> {
  const { data } = await api.get<{ data: TagSummary[] }>('/tags', { params: { popular: 1, limit } });
  return data.data;
}

/** Top tags for the map filter bar chips (T-032) / search suggestions. */
export function usePopularTags() {
  return useQuery({
    queryKey: queryKeys.tagsPopular(),
    queryFn: () => fetchPopularTags(15),
    staleTime: 10 * 60_000,
  });
}

/**
 * A broad catalog of the most-used tags (max the endpoint allows), cached for
 * the session. Used as the tag-filter candidate set on GLOBAL surfaces (a guest
 * browsing the public map); authed users filter their own places, so they use
 * {@link useMyPlacesTags} instead. The filter searches this list *client-side*
 * — over each tag's localized label as well as its raw name/slug — because tags
 * are stored in English and only displayed in Spanish, so the server (which
 * searches the English text) can't match a Spanish query. Client-side matching
 * also gives case-insensitive, accent-insensitive, substring search for free.
 */
export function useTagCatalog(opts?: { enabled?: boolean }) {
  return useQuery({
    queryKey: queryKeys.tagsCatalog(),
    queryFn: () => fetchPopularTags(100),
    staleTime: 10 * 60_000,
    enabled: opts?.enabled ?? true,
  });
}

async function fetchMyPlacesTags(): Promise<TagSummary[]> {
  const { data } = await api.get<{ data: TagSummary[] }>('/me/places/tags');
  return data.data;
}

/**
 * The discovery-tag facet of my places (ADR-084) — the tags actually on my
 * collection, most-used first, with a per-tag count. This is the *complete,
 * bounded* candidate set for the filter (unlike the global popular catalog), so
 * the autocomplete can't offer a tag that matches zero of my places. Authed
 * only; the caller gates `enabled` on the session.
 */
export function useMyPlacesTags(opts?: { enabled?: boolean }) {
  return useQuery({
    queryKey: queryKeys.myPlacesTags(),
    queryFn: fetchMyPlacesTags,
    staleTime: 5 * 60_000,
    enabled: opts?.enabled ?? true,
  });
}
