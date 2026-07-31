import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { NotificationRow, NotificationsPage } from '../notifications';

const PAGE_SIZE = 25;

/**
 * The notification center list (T-040) — cursor-paginated, newest first.
 *
 * `meta.unread_count` rides on every page, so {@see useUnreadCount} can badge
 * from whatever the list last fetched rather than issuing a second request.
 */
export function useNotifications(opts?: { enabled?: boolean }) {
  return useInfiniteQuery({
    queryKey: queryKeys.notifications(),
    queryFn: async ({ pageParam }): Promise<NotificationsPage> => {
      const params: Record<string, string | number> = { limit: PAGE_SIZE };
      if (pageParam) params.cursor = pageParam;
      const { data } = await api.get<NotificationsPage>('/notifications', { params });
      return data;
    },
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.next_cursor,
    staleTime: 30_000,
    enabled: opts?.enabled ?? true,
  });
}

/**
 * The unread badge count, read from the list's own `meta` (T-040).
 *
 * Deliberately NOT its own endpoint: the count the user should see is the one
 * consistent with the rows they are looking at, and a separate poll would drift
 * from it — showing a badge for a notification already visible and read.
 */
export function useUnreadCount(): number {
  const { data } = useNotifications();

  return data?.pages[0]?.meta.unread_count ?? 0;
}

/**
 * Mark notifications read — specific ids, or everything.
 *
 * Optimistic, because the alternative is a badge that lingers for a round trip
 * after the user has visibly dealt with it. On failure the cache is rolled back
 * to exactly what it was, so a failed clear does not silently eat the unread
 * state.
 */
export function useMarkRead() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (v: { ids?: string[]; all?: boolean }): Promise<number> => {
      const { data } = await api.post<{ data: { unread_count: number } }>('/notifications/read', v);
      return data.data.unread_count;
    },
    onMutate: async (v) => {
      await qc.cancelQueries({ queryKey: queryKeys.notifications() });
      const previous = qc.getQueryData<{ pages: NotificationsPage[]; pageParams: unknown[] }>(
        queryKeys.notifications(),
      );

      qc.setQueryData<{ pages: NotificationsPage[]; pageParams: unknown[] }>(
        queryKeys.notifications(),
        (old) => {
          if (!old) return old;
          const readAt = new Date().toISOString();
          const shouldMark = (row: NotificationRow) => v.all || (v.ids ?? []).includes(row.id);
          let cleared = 0;

          const pages = old.pages.map((page) => ({
            ...page,
            data: page.data.map((row) => {
              if (row.read_at !== null || !shouldMark(row)) return row;
              cleared++;
              return { ...row, read_at: readAt };
            }),
          }));

          // Recompute the badge from the same edit, so the count and the row
          // tints can never disagree mid-flight.
          return {
            ...old,
            pages: pages.map((page) => ({
              ...page,
              meta: {
                ...page.meta,
                unread_count: v.all ? 0 : Math.max(0, page.meta.unread_count - cleared),
              },
            })),
          };
        },
      );

      return { previous };
    },
    onError: (_e, _v, context) => {
      if (context?.previous) qc.setQueryData(queryKeys.notifications(), context.previous);
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: queryKeys.notifications() });
    },
  });
}
