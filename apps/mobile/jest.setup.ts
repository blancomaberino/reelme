/* eslint-disable @typescript-eslint/no-require-imports */
import { notifyManager } from '@tanstack/react-query';

// Flush React Query notifications synchronously so no batching setTimeout lingers
// past a test — that timer both fires act(...) warnings and blocks the worker exit.
notifyManager.setScheduler((cb) => cb());

// In-memory SecureStore (no native module in jest).
jest.mock('expo-secure-store', () => {
  const store = new Map<string, string>();
  return {
    getItemAsync: jest.fn(async (k: string) => store.get(k) ?? null),
    setItemAsync: jest.fn(async (k: string, v: string) => {
      store.set(k, v);
    }),
    deleteItemAsync: jest.fn(async (k: string) => {
      store.delete(k);
    }),
  };
});

jest.mock('expo-device', () => ({ deviceName: 'jest-device' }));

// No native splash module in jest — the auth gate awaits these.
jest.mock('expo-splash-screen', () => ({
  preventAutoHideAsync: jest.fn(async () => {}),
  hideAsync: jest.fn(async () => {}),
}));

// expo-router: the single canonical mock for the whole suite. A mock declared in
// setupFilesAfterEnv always overrides a test file's own jest.mock('expo-router'),
// so every test shares this one — it captures imperative navigation (router.*),
// the entry Redirect target, and the Tabs wiring. Reset the capture fields in a
// test's beforeEach as needed.
export const mockRouter = {
  replace: jest.fn(),
  push: jest.fn(),
  back: jest.fn(),
  redirectHref: null as string | null,
  initialRouteName: null as string | null,
  tabNames: [] as string[],
};
jest.mock('expo-router', () => {
  const React = require('react');
  return {
    router: mockRouter,
    useRouter: () => mockRouter,
    useSegments: () => [],
    Link: ({ children }: { children: React.ReactNode }) => children,
    Redirect: ({ href }: { href: string }) => {
      mockRouter.redirectHref = href;
      return null;
    },
    Stack: Object.assign(() => null, { Screen: () => null }),
    Tabs: Object.assign(
      ({ children, initialRouteName }: { children?: React.ReactNode; initialRouteName?: string }) => {
        mockRouter.initialRouteName = initialRouteName ?? null;
        return React.createElement(React.Fragment, null, children);
      },
      {
        Screen: ({ name }: { name: string }) => {
          mockRouter.tabNames.push(name);
          return null;
        },
      },
    ),
  };
});

// Silence the reanimated/native-only warnings that don't affect logic tests.
jest.mock('react-native-reanimated', () => require('react-native-reanimated/mock'), { virtual: true });

// Safe-area context needs a provider at runtime; in tests, stub insets to 0 and
// render the provider/view as passthroughs so screens mount without a provider.
jest.mock('react-native-safe-area-context', () => {
  const React = require('react');
  const inset = { top: 0, right: 0, bottom: 0, left: 0 };
  return {
    SafeAreaProvider: ({ children }: { children: React.ReactNode }) => children,
    SafeAreaView: ({ children, ...props }: { children: React.ReactNode }) =>
      React.createElement(require('react-native').View, props, children),
    useSafeAreaInsets: () => inset,
    useSafeAreaFrame: () => ({ x: 0, y: 0, width: 390, height: 844 }),
  };
});
