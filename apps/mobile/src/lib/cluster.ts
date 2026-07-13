import Supercluster from 'supercluster';

import type { MapPin } from '@/api/places';

import type { Bbox } from './geo';

export type ClusterItem =
  | { kind: 'pin'; pin: MapPin }
  | { kind: 'cluster'; id: number; lng: number; lat: number; count: number };

type PinProps = { pinId: string };

/**
 * Build a supercluster index over raw pins (05-mobile-app §4.1). Kept in a
 * `useMemo` keyed by the pin set — constructing it is O(n log n), not a
 * per-render cost. radius 50px / maxZoom 16 collapse dense fields client-side
 * once the server has stopped clustering (zoom ≥ 15).
 */
export function buildClusterIndex(pins: MapPin[]): Supercluster<PinProps> {
  const byId = new Map(pins.map((p) => [p.id, p]));
  const index = new Supercluster<PinProps>({ radius: 50, maxZoom: 16 });
  index.load(
    pins.map((p) => ({
      type: 'Feature' as const,
      properties: { pinId: p.id },
      geometry: { type: 'Point' as const, coordinates: [p.lng, p.lat] },
    })),
  );
  // Attach the lookup so callers can resolve leaf points back to pins.
  (index as Supercluster<PinProps> & { __byId: Map<string, MapPin> }).__byId = byId;
  return index;
}

/** Renderable cluster/pin items for a viewport at an integer zoom. */
export function clusterItems(index: Supercluster<PinProps>, bbox: Bbox, zoom: number): ClusterItem[] {
  const byId = (index as Supercluster<PinProps> & { __byId: Map<string, MapPin> }).__byId;
  const features = index.getClusters(bbox, Math.round(zoom));

  return features.map((f): ClusterItem => {
    const [lng, lat] = f.geometry.coordinates;
    const props = f.properties as { cluster?: boolean; cluster_id?: number; point_count?: number; pinId?: string };
    if (props.cluster) {
      return { kind: 'cluster', id: props.cluster_id!, lng, lat, count: props.point_count ?? 0 };
    }
    const pin = byId.get(String(props.pinId));
    // Fall back to a synthetic pin if the lookup misses (shouldn't happen).
    return { kind: 'pin', pin: pin ?? ({ id: String(props.pinId), lat, lng } as MapPin) };
  });
}

/** Zoom that explodes a client cluster into its children (for animateToRegion). */
export function clusterExpansionZoom(index: Supercluster<PinProps>, clusterId: number): number {
  return index.getClusterExpansionZoom(clusterId);
}
