import {
  bboxToRegion,
  distanceM,
  mapQueryFor,
  padBbox,
  quantizeBbox,
  regionRadiusM,
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

/*
 * The offers map asks the API for "what is in this viewport". Get the radius
 * wrong and a venue the user has dragged into view is not in the answer.
 */
describe('regionRadiusM', () => {
  it('covers the CORNERS of the viewport, not just the nearest edge', () => {
    // ~11.1km tall, ~11.1km wide at the equator → half-diagonal ≈ 7.9km, which
    // is larger than half the height (5.6km). A radius drawn to the nearest
    // edge would leave every corner of the map unsearched.
    const radius = regionRadiusM({ latitude: 0, longitude: 0, latitudeDelta: 0.1, longitudeDelta: 0.1 });

    expect(radius).toBeGreaterThan(7_800);
    expect(radius).toBeLessThan(8_000);
  });

  it('shrinks the longitude span toward the poles', () => {
    const equator = regionRadiusM({ latitude: 0, longitude: 0, latitudeDelta: 0.1, longitudeDelta: 0.1 });
    const north = regionRadiusM({ latitude: 60, longitude: 0, latitudeDelta: 0.1, longitudeDelta: 0.1 });

    // A degree of longitude is half as wide at 60°N, so the same viewport
    // covers less ground — asking for the equator's radius there would request
    // roughly twice the area actually shown.
    expect(north).toBeLessThan(equator);
  });

  it('grows with the viewport', () => {
    const close = regionRadiusM({ latitude: -34.9, longitude: -56.16, latitudeDelta: 0.02, longitudeDelta: 0.02 });
    const wide = regionRadiusM({ latitude: -34.9, longitude: -56.16, latitudeDelta: 0.5, longitudeDelta: 0.5 });

    expect(wide).toBeGreaterThan(close * 20);
  });
});

describe('distanceM', () => {
  // Montevideo, where the app actually runs. At -34.9° a degree of longitude is
  // ~82% of a degree of latitude, and that factor is the whole reason the
  // haversine carries its `cos(lat)` term.
  const HOME = { latitude: -34.9011, longitude: -56.1645 };
  const M_PER_DEG_LAT = 111_320;

  it('measures a north-south step', () => {
    const north = { latitude: HOME.latitude + 10_000 / M_PER_DEG_LAT, longitude: HOME.longitude };

    expect(distanceM(HOME, north)).toBeGreaterThan(9_900);
    expect(distanceM(HOME, north)).toBeLessThan(10_100);
  });

  it('SHRINKS an east-west step by cos(latitude) — the term every other test misses', () => {
    // Every caller-side test steps due north, so the longitude half of the
    // formula is never exercised: delete `cos(a.lat) * cos(b.lat)` and the whole
    // suite stays green while east-west distances over-read by ~22% here.
    //
    // What that costs: `shouldCenterOnViewer` compares against a 30 km radius,
    // so a viewer 27 km EAST of the frame they left measures as ~33 km, lands
    // outside the bound, and the map silently never re-frames onto them.
    const sameDegrees = 0.1;
    const north = { latitude: HOME.latitude + sameDegrees, longitude: HOME.longitude };
    const east = { latitude: HOME.latitude, longitude: HOME.longitude + sameDegrees };

    const ratio = distanceM(HOME, east) / distanceM(HOME, north);

    // cos(34.9°) ≈ 0.820.
    expect(ratio).toBeGreaterThan(0.80);
    expect(ratio).toBeLessThan(0.84);
  });

  it('is zero for a point against itself, and symmetric', () => {
    const other = { latitude: -34.88, longitude: -56.19 };

    expect(distanceM(HOME, HOME)).toBe(0);
    expect(distanceM(HOME, other)).toBeCloseTo(distanceM(other, HOME), 6);
  });
});
