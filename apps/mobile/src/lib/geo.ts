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

/**
 * Decimal places kept on a `near=lat,lng` the API is asked with.
 *
 * Four is ~11 m at this latitude — finer than any distance label we render, and
 * coarse enough that a phone sitting still on a table (whose fix wanders a few
 * metres) does not mint a fresh cache entry, and a fresh request, every time it
 * twitches. Mirrored server-side by `ParsesNearPoint::NEAR_PRECISION`, which
 * rounds again on arrival — the client's rounding is a courtesy, the server's is
 * the control.
 */
export const NEAR_PRECISION = 4;

/** The `near=lat,lng` a request carries, or null for a viewer with no position. */
export function nearParam(at: Pick<Region, 'latitude' | 'longitude'> | null | undefined): string | null {
  if (!at) return null;

  return `${at.latitude.toFixed(NEAR_PRECISION)},${at.longitude.toFixed(NEAR_PRECISION)}`;
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

/** Metres per degree of latitude — near enough at any latitude for a radius. */
const METRES_PER_DEGREE = 111_320;

/**
 * A radius in metres that covers the whole visible region.
 *
 * Half the viewport's DIAGONAL, not half its height: a radius drawn from the
 * centre to the nearest edge leaves the four corners outside, and the corner of
 * the map is exactly where a user drags a venue they are looking for.
 *
 * Longitude degrees shrink toward the poles, so the horizontal span is scaled
 * by cos(latitude) — without it a viewport in Reykjavík asks for roughly twice
 * the area it shows.
 */
export function regionRadiusM(region: Region): number {
  const latM = region.latitudeDelta * METRES_PER_DEGREE;
  const lngM = region.longitudeDelta * METRES_PER_DEGREE * Math.cos((region.latitude * Math.PI) / 180);

  return Math.round(Math.hypot(latM, lngM) / 2);
}

/** Mean Earth radius, metres — the WGS84 authalic radius the haversine assumes. */
const EARTH_RADIUS_M = 6_371_008.8;

/**
 * Great-circle distance in metres between two points.
 *
 * Haversine rather than the flat approximation `regionRadiusM` uses above: that
 * one measures a viewport, where a few percent either way changes nothing, while
 * this one decides whether the map yanks the user somewhere. It is also the
 * client's ONLY distance function — the metres a pin shows are computed by
 * PostGIS server-side (T-156), never re-derived here, because the two would
 * disagree and the server's answer is the one the sort ordering used.
 */
export function distanceM(
  a: { latitude: number; longitude: number },
  b: { latitude: number; longitude: number },
): number {
  const rad = Math.PI / 180;
  const dLat = (b.latitude - a.latitude) * rad;
  const dLng = (b.longitude - a.longitude) * rad;
  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(a.latitude * rad) * Math.cos(b.latitude * rad) * Math.sin(dLng / 2) ** 2;

  return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(h)));
}
