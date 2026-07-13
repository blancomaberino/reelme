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
  canGoBack: jest.fn(() => true),
  redirectHref: null as string | null,
  initialRouteName: null as string | null,
  tabNames: [] as string[],
  // Params returned by useLocalSearchParams — set in a test's beforeEach.
  params: {} as Record<string, string>,
};
jest.mock('expo-router', () => {
  const React = require('react');
  return {
    router: mockRouter,
    useRouter: () => mockRouter,
    useSegments: () => [],
    useLocalSearchParams: () => mockRouter.params,
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

// GestureHandlerRootView (wraps the app in _layout for gorhom) calls a native
// install() absent in jest — render it as a passthrough View. gorhom itself is
// mocked separately, so nothing else needs the real gesture-handler here.
jest.mock('react-native-gesture-handler', () => {
  const React = require('react');
  const { View } = require('react-native');
  return {
    GestureHandlerRootView: ({ children, style }: { children?: React.ReactNode; style?: unknown }) =>
      React.createElement(View, { style }, children),
  };
});

// react-native-maps is native — render MapView/Marker as passthrough Views so
// screens embedding a map (place detail mini-map, map screen) mount in jest.
// MapView forwards a ref exposing a no-op animateToRegion (called on cluster tap).
jest.mock('react-native-maps', () => {
  const React = require('react');
  const { View } = require('react-native');
  const passthrough = (name: string) =>
    Object.assign(
      ({ children, ...props }: { children?: React.ReactNode; testID?: string }) =>
        React.createElement(View, { ...props, testID: props.testID ?? name }, children),
      { displayName: name },
    );
  const MapView = Object.assign(
    React.forwardRef(({ children, ...props }: { children?: React.ReactNode }, ref: unknown) => {
      React.useImperativeHandle(ref, () => ({ animateToRegion: jest.fn() }));
      return React.createElement(View, { ...props, testID: 'MapView' }, children);
    }),
    { displayName: 'MapView' },
  );
  return {
    __esModule: true,
    default: MapView,
    MapView,
    Marker: passthrough('Marker'),
    Callout: passthrough('Callout'),
    PROVIDER_DEFAULT: undefined,
    PROVIDER_GOOGLE: 'google',
  };
});

// @shopify/flash-list is native — render a lightweight list that maps data
// through renderItem (+ header/footer/empty) so feed/search screens mount and
// assert on rows in jest.
jest.mock('@shopify/flash-list', () => {
  const React = require('react');
  const { View } = require('react-native');
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const resolve = (node: any) => (typeof node === 'function' ? node() : node);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const FlashList = (props: any) => {
    const { data = [], renderItem, keyExtractor, ListHeaderComponent, ListFooterComponent, ListEmptyComponent } = props;
    return React.createElement(
      View,
      { testID: 'flash-list' },
      resolve(ListHeaderComponent),
      data.length === 0
        ? resolve(ListEmptyComponent)
        : // eslint-disable-next-line @typescript-eslint/no-explicit-any
          data.map((item: any, index: number) =>
            React.createElement(
              React.Fragment,
              { key: keyExtractor ? keyExtractor(item, index) : index },
              renderItem?.({ item, index }),
            ),
          ),
      resolve(ListFooterComponent),
    );
  };
  return { __esModule: true, FlashList };
});

// @gorhom/bottom-sheet needs reanimated/gesture-handler native bits — render
// its container/view as passthroughs so the map screen mounts in jest.
jest.mock('@gorhom/bottom-sheet', () => {
  const React = require('react');
  const { View } = require('react-native');
  const BottomSheet = React.forwardRef(({ children }: { children?: React.ReactNode }, _ref: unknown) =>
    React.createElement(View, { testID: 'BottomSheet' }, children),
  );
  return {
    __esModule: true,
    default: BottomSheet,
    BottomSheetView: ({ children }: { children?: React.ReactNode }) => React.createElement(View, null, children),
  };
});

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
