import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import * as SecureStore from 'expo-secure-store';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { useLogout } from '@/api/hooks/useAuth';
import { queryKeys } from '@/api/keys';
import { getToken, setToken } from '@/api/token';
import { queryPersister } from '@/lib/query-persist';
import { useSessionStore } from '@/stores/session';

import { mockAsyncStorage } from '../../../../jest.setup';

/**
 * The persisted query cache (T-103) is the signed-in account's private
 * collection sitting in plaintext on the device. Signing out has to take it
 * off disk, not just out of memory — otherwise the next person to use a shared
 * phone can rehydrate the previous user's saved places.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(async () => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onPost('/auth/logout').reply(204);
  mock.onDelete(/\/devices\/.*/).reply(204);
  await setToken('tok_1');
  useSessionStore.setState({ user: null, status: 'authed' });
});

afterEach(() => {
  mock.restore();
});

it('wipes the persisted cache from disk on sign-out', async () => {
  qc.setQueryData(queryKeys.me, { id: '1', username: 'ada' });
  await queryPersister.persistClient({ buster: 'v1', timestamp: 1, clientState: { mutations: [], queries: [] } });
  await waitFor(() => expect(mockAsyncStorage.store.size).toBe(1));

  const { result } = renderHook(() => useLogout(), { wrapper });
  await act(async () => {
    await result.current.mutateAsync();
  });

  await waitFor(() => expect(mockAsyncStorage.store.size).toBe(0));
  expect(qc.getQueryData(queryKeys.me)).toBeUndefined();
  expect(await getToken()).toBeNull();
  expect(useSessionStore.getState().status).toBe('guest');
});

it('still wipes the disk cache when the Keychain wipe throws', async () => {
  // The teardown used to be a straight `await` chain, so an unreachable
  // Keychain skipped every step after it — and the step after it is the one
  // that takes this account's saved places off disk. On a shared phone that is
  // the whole risk, and it is the failure LEAST likely to be noticed, because
  // sign-out still looks like it worked.
  (SecureStore.deleteItemAsync as jest.Mock).mockRejectedValueOnce(new Error('keychain unavailable'));

  qc.setQueryData(queryKeys.me, { id: '1', username: 'ada' });
  await queryPersister.persistClient({ buster: 'v1', timestamp: 1, clientState: { mutations: [], queries: [] } });
  await waitFor(() => expect(mockAsyncStorage.store.size).toBe(1));

  const { result } = renderHook(() => useLogout(), { wrapper });
  await act(async () => {
    await result.current.mutateAsync();
  });

  // Every later wipe still ran, and the app no longer presents as signed in.
  await waitFor(() => expect(mockAsyncStorage.store.size).toBe(0));
  expect(qc.getQueryData(queryKeys.me)).toBeUndefined();
  expect(useSessionStore.getState().status).toBe('guest');
});
