// Pure geo helpers for the map screen (T-032): region ↔ bbox ↔ zoom, viewport
// padding, and query-key quantization. No react-native-maps import so these
// stay trivially unit-testable.

export type Region = {
  latitude: number;
  longitude: number;
  latitudeDelta: number;
  longitudeDelta: number;
};

/** [minLng, minLat, maxLng, maxLat] — the order the API expects. */
export type Bbox = [number, number, number, number];

const LAT_LIMIT = 85; // Web-Mercator practical limit (the API rejects beyond this).

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/** Slippy-map zoom from the visible longitude span. */
export function zoomFromRegion(region: Region): number {
  const span = Math.max(region.longitudeDelta, 1e-6);
  return Math.log2(360 / span);
}

/** Integer zoom band 1–20 — the cache-key granularity (a tiny zoom nudge reuses cache). */
export function zoomBand(zoom: number): number {
  return clamp(Math.round(zoom), 1, 20);
}

export function regionToBbox(region: Region): Bbox {
  const halfLng = region.longitudeDelta / 2;
  const halfLat = region.latitudeDelta / 2;
  return [
    region.longitude - halfLng,
    region.latitude - halfLat,
    region.longitude + halfLng,
    region.latitude + halfLat,
  ];
}

/**
 * Expand a bbox by `factor` of its span on each edge (default 40%) so small
 * pans stay inside the last fetch, then clamp to valid ranges. Longitudes are
 * clamped to [-180, 180] (never wrapped — the API 422s on an antimeridian-
 * crossing box); latitudes to the Mercator limit.
 */
export function padBbox(bbox: Bbox, factor = 0.4): Bbox {
  const [minLng, minLat, maxLng, maxLat] = bbox;
  const lngPad = (maxLng - minLng) * factor;
  const latPad = (maxLat - minLat) * factor;
  return [
    clamp(minLng - lngPad, -180, 180),
    clamp(minLat - latPad, -LAT_LIMIT, LAT_LIMIT),
    clamp(maxLng + lngPad, -180, 180),
    clamp(maxLat + latPad, -LAT_LIMIT, LAT_LIMIT),
  ];
}

/**
 * Snap a bbox to a zoom-dependent grid and stringify it — the cache key. Two
 * nearby viewports at the same zoom band round to the same grid cell (one cache
 * entry, no network on a tiny pan); a large pan crosses a cell and re-fetches.
 * Grid gets finer as you zoom in.
 */
export function quantizeBbox(bbox: Bbox, band: number): string {
  // Cell size halves per zoom level; +2 makes the grid a bit finer than the band.
  const cell = 360 / 2 ** (band + 2);
  const snap = (v: number) => Math.round(v / cell) * cell;
  return bbox.map((v) => snap(v).toFixed(5)).join(',');
}

export type MapQuery = {
  /** Padded, clamped bbox to actually request. */
  bbox: Bbox;
  /** Integer zoom passed to the API. */
  zoom: number;
  band: number;
  /** Stable cache-key fragment. */
  quantized: string;
};

/** Everything the map data hook needs, derived from the current region. */
export function mapQueryFor(region: Region): MapQuery {
  const zoom = zoomFromRegion(region);
  const band = zoomBand(zoom);
  const bbox = padBbox(regionToBbox(region));
  return { bbox, zoom: band, band, quantized: quantizeBbox(bbox, band) };
}

/** `minLng,minLat,maxLng,maxLat` query-string value for the API. */
export function bboxParam(bbox: Bbox): string {
  return bbox.map((v) => v.toFixed(6)).join(',');
}

/** Center a region on an expansion bbox (cluster tap → animateToRegion). */
export function bboxToRegion(bbox: Bbox, pad = 1.3): Region {
  const [minLng, minLat, maxLng, maxLat] = bbox;
  return {
    latitude: (minLat + maxLat) / 2,
    longitude: (minLng + maxLng) / 2,
    latitudeDelta: Math.max((maxLat - minLat) * pad, 0.002),
    longitudeDelta: Math.max((maxLng - minLng) * pad, 0.002),
  };
}
