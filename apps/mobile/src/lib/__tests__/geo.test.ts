import {
  bboxToRegion,
  mapQueryFor,
  padBbox,
  quantizeBbox,
  regionToBbox,
  zoomBand,
  zoomFromRegion,
  type Region,
} from '../geo';

const region = (over: Partial<Region> = {}): Region => ({
  latitude: -34.9,
  longitude: -56.16,
  latitudeDelta: 0.15,
  longitudeDelta: 0.15,
  ...over,
});

describe('zoomFromRegion / zoomBand', () => {
  it('derives a higher zoom for a smaller span', () => {
    const wide = zoomFromRegion(region({ longitudeDelta: 10 }));
    const tight = zoomFromRegion(region({ longitudeDelta: 0.01 }));
    expect(tight).toBeGreaterThan(wide);
  });
  it('clamps the band to 1..20', () => {
    expect(zoomBand(-5)).toBe(1);
    expect(zoomBand(99)).toBe(20);
    expect(zoomBand(12.4)).toBe(12);
  });
});

describe('regionToBbox', () => {
  it('produces [minLng, minLat, maxLng, maxLat]', () => {
    const [minLng, minLat, maxLng, maxLat] = regionToBbox(region({ latitudeDelta: 0.1, longitudeDelta: 0.2 }));
    expect(minLng).toBeCloseTo(-56.26);
    expect(maxLng).toBeCloseTo(-56.06);
    expect(minLat).toBeCloseTo(-34.95);
    expect(maxLat).toBeCloseTo(-34.85);
  });
});

describe('padBbox', () => {
  it('expands the box by the factor and clamps to valid ranges', () => {
    const padded = padBbox([-56.2, -34.95, -56.1, -34.85], 0.4);
    // 0.1 span * 0.4 = 0.04 pad each edge.
    expect(padded[0]).toBeCloseTo(-56.24);
    expect(padded[2]).toBeCloseTo(-56.06);
  });
  it('never wraps past the antimeridian or the mercator limit', () => {
    const padded = padBbox([-179.9, 84.9, 179.9, 89], 0.4);
    expect(padded[0]).toBe(-180);
    expect(padded[2]).toBe(180);
    expect(padded[1]).toBeGreaterThanOrEqual(-85);
    expect(padded[3]).toBeLessThanOrEqual(85);
  });
});

describe('quantizeBbox (cache-key stability)', () => {
  it('gives two nearby viewports at the same zoom the SAME key', () => {
    const band = zoomBand(zoomFromRegion(region()));
    const a = quantizeBbox(padBbox(regionToBbox(region({ latitude: -34.9 })), 0.4), band);
    // Nudge the center by a hair (well within one grid cell).
    const b = quantizeBbox(padBbox(regionToBbox(region({ latitude: -34.9001 })), 0.4), band);
    expect(a).toBe(b);
  });
  it('gives a large pan a DIFFERENT key', () => {
    const band = zoomBand(zoomFromRegion(region()));
    const a = quantizeBbox(padBbox(regionToBbox(region({ longitude: -56.16 })), 0.4), band);
    const b = quantizeBbox(padBbox(regionToBbox(region({ longitude: -50.0 })), 0.4), band);
    expect(a).not.toBe(b);
  });
});

describe('mapQueryFor', () => {
  it('bundles bbox, integer zoom band, and the quantized key', () => {
    const q = mapQueryFor(region());
    expect(q.zoom).toBe(q.band);
    expect(Number.isInteger(q.band)).toBe(true);
    expect(q.quantized.split(',')).toHaveLength(4);
  });
});

describe('bboxToRegion', () => {
  it('centers on the cluster expand bbox with a floor delta', () => {
    const r = bboxToRegion([-56.2, -34.95, -56.1, -34.85]);
    expect(r.latitude).toBeCloseTo(-34.9);
    expect(r.longitude).toBeCloseTo(-56.15);
    expect(r.latitudeDelta).toBeGreaterThanOrEqual(0.002);
  });
});
