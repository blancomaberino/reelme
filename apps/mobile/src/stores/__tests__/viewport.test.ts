import * as SecureStore from 'expo-secure-store';

import { clearSavedViewport } from '@/lib/viewport';

import { useViewportStore } from '../viewport';

// T-100: the remembered-viewport store. `hydrated` exists to distinguish "no
// saved viewport" from "haven't looked yet" — the map screen's loading gate
// depends on that difference, so it is pinned here.

const REGION = { latitude: 51.5, longitude: -0.12, latitudeDelta: 0.05, longitudeDelta: 0.05 };

beforeEach(async () => {
  await clearSavedViewport();
  useViewportStore.setState({ saved: null, hydrated: false });
  jest.clearAllMocks();
});

it('starts un-hydrated with nothing saved', () => {
  expect(useViewportStore.getState()).toMatchObject({ saved: null, hydrated: false });
});

it('hydrates a previously remembered viewport', async () => {
  await SecureStore.setItemAsync('map_viewport', JSON.stringify(REGION));

  await useViewportStore.getState().hydrate();

  expect(useViewportStore.getState()).toMatchObject({ saved: REGION, hydrated: true });
});

it('marks itself hydrated even when nothing was saved', async () => {
  await useViewportStore.getState().hydrate();

  // The map screen must be able to tell this apart from the pre-hydration state,
  // or a first launch would hang on the loading gate forever.
  expect(useViewportStore.getState()).toMatchObject({ saved: null, hydrated: true });
});

it('remembers a viewport in memory and on disk', async () => {
  useViewportStore.getState().remember(REGION);

  expect(useViewportStore.getState().saved).toEqual(REGION);
  await Promise.resolve();
  expect(await SecureStore.getItemAsync('map_viewport')).toEqual(JSON.stringify(REGION));
});

it('forgets the viewport in memory and on disk when cleared', async () => {
  useViewportStore.getState().remember(REGION);
  await Promise.resolve();

  await useViewportStore.getState().clear();

  // A viewport is coarse location data — sign-out must leave nothing behind for
  // the next person to sign in on a shared device.
  expect(useViewportStore.getState().saved).toBeNull();
  expect(await SecureStore.getItemAsync('map_viewport')).toBeNull();
  // Still hydrated: we have looked, there is simply nothing saved. Flipping this
  // back to false would hang the map screen's loading gate.
  expect(useViewportStore.getState().hydrated).toBe(true);
});

it('survives an unreadable store by reporting nothing saved', async () => {
  jest.mocked(SecureStore.getItemAsync).mockRejectedValueOnce(new Error('keychain unavailable'));

  await useViewportStore.getState().hydrate();

  expect(useViewportStore.getState()).toMatchObject({ saved: null, hydrated: true });
});
