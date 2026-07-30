import * as SecureStore from 'expo-secure-store';

import { clearSavedViewport, loadSavedViewport, saveViewport } from '../viewport';

// T-100: the remembered viewport. The read path must NEVER throw or return
// garbage — a corrupt value has to degrade to "nothing saved" so the map falls
// through to its next option instead of opening on a broken region.

const getItem = jest.mocked(SecureStore.getItemAsync);
const setItem = jest.mocked(SecureStore.setItemAsync);
const deleteItem = jest.mocked(SecureStore.deleteItemAsync);

const REGION = { latitude: 51.5, longitude: -0.12, latitudeDelta: 0.05, longitudeDelta: 0.05 };

/** Drain the microtask queue so a fire-and-forget write reaches SecureStore. */
const flush = () => new Promise((resolve) => setImmediate(resolve));

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

it('restores a legitimately deep zoom (the guard must not reject max pinch-zoom)', async () => {
  // Native max zoom lands around 5e-5; an over-eager floor would silently drop
  // the saved viewport for anyone who pinched all the way in.
  const deep = { latitude: 51.5, longitude: -0.12, latitudeDelta: 5e-5, longitudeDelta: 5e-5 };
  saveViewport(deep);
  await Promise.resolve();

  expect(await loadSavedViewport()).toEqual(deep);
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

it('swallows a failed delete rather than rejecting the sign-out', async () => {
  deleteItem.mockRejectedValueOnce(new Error('keychain unavailable'));

  // Sign-out awaits this; a rejection here would blow up the whole flow over a
  // viewport nobody can see.
  await expect(clearSavedViewport()).resolves.toBeUndefined();
});

it('a write still in flight cannot resurrect the viewport after a clear', async () => {
  // The privacy-relevant ordering: `saveViewport` is fire-and-forget and fires
  // on every map settle, so a write can easily be mid-keychain when sign-out
  // clears. If it landed afterwards, the next person to sign in on this device
  // would open the map on the previous user's last position.
  // Defer the write but still PERFORM it on release — a mock that resolves
  // without storing anything would pass whether or not the ordering holds.
  const realSetItem = setItem.getMockImplementation()!;
  let releaseWrite = () => {};
  setItem.mockImplementationOnce(
    (key, value) =>
      new Promise<void>((resolve) => {
        releaseWrite = () => resolve(realSetItem(key, value));
      }),
  );

  saveViewport(REGION);
  await flush(); // let the write actually reach the (blocked) keychain call
  const cleared = clearSavedViewport();
  releaseWrite(); // the pending write resolves only now, AFTER clear was called
  await cleared;

  expect(deleteItem).toHaveBeenCalledWith('map_viewport');
  expect(await loadSavedViewport()).toBeNull();
});
