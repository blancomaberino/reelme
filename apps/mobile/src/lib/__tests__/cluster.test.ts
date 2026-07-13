import type { MapPin } from '@/api/places';

import { buildClusterIndex, clusterItems } from '../cluster';
import type { Bbox } from '../geo';

function pin(id: string, lat: number, lng: number): MapPin {
  return {
    type: 'place',
    id,
    name: `Place ${id}`,
    lat,
    lng,
    category: null,
    city: null,
    price_range: null,
    status: 'pending',
    tags: [],
    source_count: 1,
    has_active_offer: false,
    top_influencer: null,
  };
}

const worldBbox: Bbox = [-180, -85, 180, 85];

describe('buildClusterIndex + clusterItems', () => {
  it('collapses tightly-grouped pins into a cluster at low zoom', () => {
    const pins = Array.from({ length: 20 }, (_, i) => pin(String(i), -34.9 + i * 0.0001, -56.16 + i * 0.0001));
    const index = buildClusterIndex(pins);

    const items = clusterItems(index, worldBbox, 5);
    expect(items.some((it) => it.kind === 'cluster')).toBe(true);
    const total = items.reduce((n, it) => n + (it.kind === 'cluster' ? it.count : 1), 0);
    expect(total).toBe(20);
  });

  it('returns individual pins when zoomed in past maxZoom', () => {
    const pins = [pin('1', -34.9, -56.16), pin('2', 40.0, -3.7)];
    const index = buildClusterIndex(pins);

    const items = clusterItems(index, worldBbox, 16);
    expect(items.every((it) => it.kind === 'pin')).toBe(true);
    expect(items).toHaveLength(2);
  });

  it('resolves leaf points back to the original pin objects', () => {
    const pins = [pin('abc', -34.9, -56.16)];
    const index = buildClusterIndex(pins);
    const items = clusterItems(index, worldBbox, 16);
    expect(items[0]).toEqual({ kind: 'pin', pin: pins[0] });
  });
});
