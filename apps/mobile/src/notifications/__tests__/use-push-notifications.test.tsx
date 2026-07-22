import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import * as Notifications from 'expo-notifications';
import type { ReactNode } from 'react';

import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

import { mockRouter } from '../../../jest.setup';
import { registerForPush } from '../push';
import { usePushNotifications } from '../use-push-notifications';

jest.mock('../push', () => ({
  configureForegroundHandler: jest.fn(),
  setupAndroidChannel: jest.fn(async () => {}),
  setCurrentPath: jest.fn(),
  registerForPush: jest.fn(async () => {}),
  unregisterPush: jest.fn(async () => {}),
}));

let qc: QueryClient;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function response(url: string) {
  return { notification: { request: { content: { data: { type: 'x', url } } } } };
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  jest.clearAllMocks();
  mockRouter.push.mockClear();
  useSessionStore.setState({ status: 'guest', user: null });
  useUiStore.setState({ pendingNotificationUrl: null });
  (Notifications.getLastNotificationResponseAsync as jest.Mock).mockResolvedValue(null);
});

it('navigates to the deep-link when a notification is tapped (warm)', async () => {
  renderHook(() => usePushNotifications(), { wrapper });

  const onTap = (Notifications.addNotificationResponseReceivedListener as jest.Mock).mock.calls[0][0];
  act(() => onTap(response('/shares/7/review')));

  expect(mockRouter.push).toHaveBeenCalledWith('/shares/7/review');
});

it('invalidates the share query when a share notification arrives in foreground', async () => {
  const spy = jest.spyOn(qc, 'invalidateQueries');
  renderHook(() => usePushNotifications(), { wrapper });

  const onReceive = (Notifications.addNotificationReceivedListener as jest.Mock).mock.calls[0][0];
  // Server sends a numeric share_id in the data bag; the key must be the string form.
  act(() => onReceive({ request: { content: { data: { url: '/place/x', share_id: 42 } } } }));

  expect(spy).toHaveBeenCalledWith({ queryKey: ['shares', '42'] });
});

it('registers the push token once the session is authed', async () => {
  const { rerender } = renderHook(() => usePushNotifications(), { wrapper });
  expect(registerForPush).not.toHaveBeenCalled(); // guest → no registration

  act(() => useSessionStore.setState({ status: 'authed' }));
  rerender({});

  await waitFor(() => expect(registerForPush).toHaveBeenCalled());
});

it('stages a cold-start tap and flushes it after auth resolves', async () => {
  (Notifications.getLastNotificationResponseAsync as jest.Mock).mockResolvedValue(response('/place/tortoni'));

  const { rerender } = renderHook(() => usePushNotifications(), { wrapper });

  // Guest at launch → the tap is staged, not pushed yet.
  await waitFor(() => expect(useUiStore.getState().pendingNotificationUrl).toBe('/place/tortoni'));
  expect(mockRouter.push).not.toHaveBeenCalled();

  // Auth resolves → the staged deep-link is pushed and cleared.
  act(() => useSessionStore.setState({ status: 'authed' }));
  rerender({});

  await waitFor(() => expect(mockRouter.push).toHaveBeenCalledWith('/place/tortoni'));
  expect(useUiStore.getState().pendingNotificationUrl).toBeNull();
});
