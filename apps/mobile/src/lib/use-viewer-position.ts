import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo } from 'react';
import { AppState } from 'react-native';

import { queryKeys } from '@/api/keys';

import { positionIfGranted, VIEWER_FIX_MAX_AGE_MS } from './location';

/** A viewer's own position — the point every distance on the map is measured from. */
export type ViewerPoint = { latitude: number; longitude: number };

/**
 * The viewer's position for VIEWER-RELATIVE DATA (T-156): the `near` a map or
 * list query is measured from, not a viewport to fly to.
 *
 * It NEVER PROMPTS. That is the design constraint, not an omission: the two
 * places that may ask for location are the first-launch resolve (where the user
 * just opened a map) and the "locate me" button (where the prompt IS the point).
 * Asking a third time — silently, so a distance label could appear — would spend
 * the one dialog iOS gives us on a feature nobody requested, and a denial there
 * costs the map its "locate me" too. An ungranted permission yields null, and
 * every field derived from it is then ABSENT rather than faked.
 *
 * Held in the QUERY CACHE rather than in component state, matching the offers
 * screen (`queryKeys.deviceLocation`). Two consequences, both of which were
 * bugs when this was a `useState` + `[]` effect:
 *
 *  - **It refreshes.** With empty deps the mount-time fix was kept forever, so a
 *    viewer who walked two kilometres had every pin measured from where they
 *    started — the distance beside a cue that ages out after five minutes never
 *    aged at all.
 *  - **It is re-asked when the answer can have changed.** A permission read once
 *    at mount can go from `undetermined` to `granted` in the same session: the
 *    first-launch resolve prompts a moment later, and "locate me" prompts on
 *    demand. Both left the map with no distances until the process restarted.
 *    {@link useRefreshViewerPosition} is what a grant calls; returning to the
 *    foreground covers a grant made in Settings.
 *
 * Never throws: `positionIfGranted` degrades to null on a denied permission, a
 * hanging provider, or a dev client built without expo-location.
 */
export function useViewerPosition(): ViewerPoint | null {
  const client = useQueryClient();
  const { data, dataUpdatedAt } = useQuery({
    queryKey: queryKeys.viewerPosition(),
    queryFn: () => positionIfGranted(),
    // Matches the bound on the cached fix itself: past it, the answer we would
    // hand out is one `positionIfGranted` would already refuse to return.
    staleTime: VIEWER_FIX_MAX_AGE_MS,
    retry: false,
  });

  /*
   * A permission granted in Settings is invisible to this app until it comes
   * back to the foreground; that transition is the only signal we get.
   *
   * NOT `refetchOnWindowFocus` (which `focusManager` in `network.ts` already
   * wires to AppState): that respects `staleTime`, and the case this exists for
   * — a grant made in Settings and returned from within two minutes — is
   * precisely a FRESH cached null. It would be skipped.
   *
   * But an unconditional invalidate is the opposite mistake, and it was the
   * first version here: past two minutes `getLastKnownPositionAsync` refuses the
   * cached fix and a GPS watch spins for up to five seconds, so every resume
   * cost radio and battery to re-learn a position that had not changed. Re-ask
   * only when the answer can differ — we have none (a grant would change it), or
   * the one we have is older than we would serve. When the permission is still
   * denied, `positionIfGranted` returns null without touching the radio.
   */
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state !== 'active') return;

      const stale = Date.now() - dataUpdatedAt > VIEWER_FIX_MAX_AGE_MS;
      if (data == null || stale) {
        void client.invalidateQueries({ queryKey: queryKeys.viewerPosition() });
      }
    });

    return () => sub.remove();
  }, [client, data, dataUpdatedAt]);

  // Memoized on the fix itself: this is an effect dependency on the map screen,
  // and a fresh object literal per render re-subscribed the re-frame effect on
  // every settle and every fetch.
  return useMemo(
    () => (data ? { latitude: data.latitude, longitude: data.longitude } : null),
    [data],
  );
}

/**
 * Re-ask for the viewer's position, for the moment a permission is granted by a
 * control that OWNS its prompt — "locate me". Without it, granting there flies
 * the map to the user and leaves every pin distance-less until the app restarts.
 */
export function useRefreshViewerPosition(): () => void {
  const client = useQueryClient();

  return useCallback(() => {
    void client.invalidateQueries({ queryKey: queryKeys.viewerPosition() });
  }, [client]);
}
