import { useEffect, useState } from 'react';

import { getLocationPermission, getUserRegion } from './location';

/** A viewer's own position — the point every distance on the map is measured from. */
export type ViewerPoint = { latitude: number; longitude: number };

/**
 * The viewer's position for VIEWER-RELATIVE DATA (T-156): the `near` a map or
 * list query is measured from, not a viewport to fly to.
 *
 * It NEVER PROMPTS. That is the whole design constraint, not an omission: the
 * two places that may ask for location are the first-launch resolve (where the
 * user just opened a map) and the "locate me" button (where the prompt is the
 * point). Asking a third time — silently, so a distance label can appear — would
 * spend the one permission dialog iOS gives us on a feature the user never
 * requested, and a denial there costs the map its "locate me" too.
 *
 * So an ungranted permission simply yields null, forever, and every field
 * derived from it is ABSENT rather than faked. A pin with no distance is honest;
 * a pin claiming 0 m is not.
 *
 * Never throws: `getUserRegion` self-limits and degrades to null on a hanging or
 * missing provider (Expo Go, a dev client built before expo-location).
 */
export function useViewerPosition(): ViewerPoint | null {
  const [point, setPoint] = useState<ViewerPoint | null>(null);

  useEffect(() => {
    let active = true;

    void (async () => {
      const permission = await getLocationPermission();
      if (!active || permission.state !== 'granted') return;

      const region = await getUserRegion();
      // Guarded again after the await: a fix can take seconds, and resolving
      // onto an unmounted screen is a React state-update warning at best and a
      // leak at worst.
      if (active && region) {
        setPoint({ latitude: region.latitude, longitude: region.longitude });
      }
    })();

    return () => {
      active = false;
    };
  }, []);

  return point;
}
