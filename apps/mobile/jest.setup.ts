/* eslint-disable @typescript-eslint/no-require-imports */
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

// expo-router: capture navigation; render Link/Redirect as passthrough.
export const mockRouter = { replace: jest.fn(), push: jest.fn(), back: jest.fn() };
jest.mock('expo-router', () => {
  const React = require('react');
  return {
    router: mockRouter,
    useRouter: () => mockRouter,
    useSegments: () => [],
    Link: ({ children }: { children: React.ReactNode }) => children,
    Redirect: () => null,
    Stack: Object.assign(() => null, { Screen: () => null }),
    Tabs: Object.assign(() => null, { Screen: () => null }),
  };
});

// Silence the reanimated/native-only warnings that don't affect logic tests.
jest.mock('react-native-reanimated', () => require('react-native-reanimated/mock'), { virtual: true });
