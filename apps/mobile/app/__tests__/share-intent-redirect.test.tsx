import { render, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';

import RootLayout from '../_layout';
import { api } from '@/api/client';
import { clearToken, setToken } from '@/api/token';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

import { mockRouter } from '../../jest.setup';

// A share is waiting in the native module every time the layout mounts. Each
// test sets `mockIntent` to the payload it wants delivered.
let mockIntent: { webUrl: string | null; text: string | null };
jest.mock('expo-share-intent', () => ({
  ShareIntentProvider: ({ children }: { children: React.ReactNode }) => children,
  useShareIntentContext: () => ({ hasShareIntent: true, shareIntent: mockIntent, resetShareIntent: jest.fn() }),
  getShareExtensionKey: () => 'sharekey',
}));

let mock: AxiosMockAdapter;

beforeEach(() => {
  mock = new AxiosMockAdapter(api);
  mockRouter.replace.mockClear();
  useUiStore.setState({ pendingShare: null });
  // Reset the auth gate to its real starting point so ShareIntentRedirect waits
  // for the bootstrap instead of inheriting a prior test's resolved status.
  useSessionStore.setState({ user: null, status: 'loading' });
  mockIntent = { webUrl: null, text: null };
});
afterEach(async () => {
  mock.restore();
  await clearToken();
});

it('stages a guest share and routes to sign-in (survives the auth gate)', async () => {
  await clearToken(); // no token → the auth gate resolves to guest
  mockIntent = { webUrl: 'https://instagram.com/reel/x', text: '' };

  render(<RootLayout />);

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(auth)/login'));
  expect(useUiStore.getState().pendingShare).toEqual({ url: 'https://instagram.com/reel/x', text: '' });
});

it('extracts a URL from shared text and routes an authed user straight to ingest', async () => {
  await setToken('tok_1');
  mock.onGet('/me').reply(200, { data: { user: { id: '1', name: 'Ada', username: 'ada', email: 'a@example.com' } } });
  mockIntent = { webUrl: null, text: 'Best tacos! https://www.tiktok.com/@a/video/1 🌮' };

  render(<RootLayout />);

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/share'));
  expect(useUiStore.getState().pendingShare).toEqual({
    url: 'https://www.tiktok.com/@a/video/1',
    text: 'Best tacos! https://www.tiktok.com/@a/video/1 🌮',
  });
});

it('ignores an in-app reelmap:// deep link instead of hijacking it to the composer (T-098)', async () => {
  await setToken('tok_1');
  mock.onGet('/me').reply(200, { data: { user: { id: '1', name: 'Ada', username: 'ada', email: 'a@example.com' } } });
  // expo-share-intent captures scheme opens (e.g. a push-notification target);
  // it must NOT be treated as a shared post.
  mockIntent = { webUrl: 'reelmap://shares/1/status', text: '' };

  render(<RootLayout />);

  // no share staged, and never bounced to the composer/sign-in
  await waitFor(() => expect(useSessionStore.getState().status).not.toBe('loading'));
  expect(useUiStore.getState().pendingShare).toBeNull();
  expect(mockRouter.replace).not.toHaveBeenCalledWith('/(main)/share');
  expect(mockRouter.replace).not.toHaveBeenCalledWith('/(auth)/login');
});
