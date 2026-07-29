import * as SecureStore from 'expo-secure-store';

import { clearSavedViewport, loadSavedViewport, saveViewport } from '../viewport';

// T-100: the remembered viewport. The read path must NEVER throw or return
// garbage — a corrupt value has to degrade to "nothing saved" so the map falls
// through to its next option instead of opening on a broken region.

const getItem = jest.mocked(SecureStore.getItemAsync);
const setItem = jest.mocked(SecureStore.setItemAsync);

const REGION = { latitude: 51.5, longitude: -0.12, latitudeDelta: 0.05, longitudeDelta: 0.05 };

beforeEach(async () => {
  await clearSavedViewport();
  jest.clearAllMocks();
});

it('round-trips a settled viewport', async () => {
  saveViewport(REGION);
  // saveViewport is fire-and-forget; let its write settle.
  await Promise.resolve();

  expect(await loadSavedViewport()).toEqual(REGION);
});

it('returns null when nothing has been saved', async () => {
  expect(await loadSavedViewport()).toBeNull();
});

it('rejects a corrupt stored value instead of throwing', async () => {
  getItem.mockResolvedValueOnce('not json at all');

  expect(await loadSavedViewport()).toBeNull();
});

it('rejects a stored region with missing or non-numeric fields', async () => {
  getItem.mockResolvedValueOnce(JSON.stringify({ latitude: 51.5, longitude: -0.12 }));
  expect(await loadSavedViewport()).toBeNull();

  getItem.mockResolvedValueOnce(JSON.stringify({ ...REGION, latitude: 'north' }));
  expect(await loadSavedViewport()).toBeNull();
});

it('rejects an out-of-range stored region', async () => {
  getItem.mockResolvedValueOnce(JSON.stringify({ ...REGION, latitude: 120 }));
  expect(await loadSavedViewport()).toBeNull();

  getItem.mockResolvedValueOnce(JSON.stringify({ ...REGION, longitude: -400 }));
  expect(await loadSavedViewport()).toBeNull();
});

it('rejects an absurd zoom (delta outside the sane band)', async () => {
  // A zero delta would render a degenerate viewport; >180 is off the planet.
  getItem.mockResolvedValueOnce(JSON.stringify({ ...REGION, latitudeDelta: 0 }));
  expect(await loadSavedViewport()).toBeNull();

  getItem.mockResolvedValueOnce(JSON.stringify({ ...REGION, longitudeDelta: 999 }));
  expect(await loadSavedViewport()).toBeNull();
});

it('refuses to persist an invalid region', () => {
  saveViewport({ ...REGION, latitude: NaN });

  expect(setItem).not.toHaveBeenCalled();
});

it('swallows a failed write rather than interrupting panning', async () => {
  setItem.mockRejectedValueOnce(new Error('keychain unavailable'));

  // Must not throw or produce an unhandled rejection.
  expect(() => saveViewport(REGION)).not.toThrow();
  await Promise.resolve();
});

it('survives an unreadable store', async () => {
  getItem.mockRejectedValueOnce(new Error('keychain unavailable'));

  expect(await loadSavedViewport()).toBeNull();
});
