/* eslint-disable @typescript-eslint/no-require-imports */
import { focusManager, notifyManager, onlineManager } from '@tanstack/react-query';

import { useSettingsStore } from '@/stores/settings';

// Flush React Query notifications synchronously so no batching setTimeout lingers
// past a test — that timer both fires act(...) warnings and blocks the worker exit.
notifyManager.setScheduler((cb) => cb());

// The app defaults to Spanish; pin tests to English so screen assertions read in
// English. Locale-specific behaviour is covered explicitly in i18n tests.
beforeEach(() => {
  useSettingsStore.setState({ locale: 'en' });
});

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

// `isDevice: true` so push registration runs in tests (a simulator reports false).
jest.mock('expo-device', () => ({ deviceName: 'jest-device', isDevice: true }));

// In-memory AsyncStorage (T-103) — backs the persisted query cache. Exposed so
// a test can seed a dehydrated cache before mounting, or read back what was
// written. `__reset()` between tests; jest.setup owns the lifecycle below.
export const mockAsyncStorage = {
  store: new Map<string, string>(),
  __reset() {
    this.store.clear();
  },
};
jest.mock('@react-native-async-storage/async-storage', () => ({
  __esModule: true,
  default: {
    getItem: jest.fn(async (k: string) => mockAsyncStorage.store.get(k) ?? null),
    setItem: jest.fn(async (k: string, v: string) => {
      mockAsyncStorage.store.set(k, v);
    }),
    removeItem: jest.fn(async (k: string) => {
      mockAsyncStorage.store.delete(k);
    }),
  },
}));

// NetInfo (T-103) — drives React Query's onlineManager. Default: connected.
// `mockNetInfo.emit({ isConnected: false })` flips the whole app offline and
// notifies every live listener, which is how the offline/reconnect tests work.
type NetInfoSnapshot = { isConnected: boolean; isInternetReachable: boolean | null };

export const mockNetInfo = {
  state: { isConnected: true, isInternetReachable: true } as NetInfoSnapshot,
  listeners: new Set<(s: NetInfoSnapshot) => void>(),
  emit(next: Partial<NetInfoSnapshot>) {
    mockNetInfo.state = { ...mockNetInfo.state, ...next };
    for (const listener of mockNetInfo.listeners) listener(mockNetInfo.state);
  },
  /**
   * Back to connected, notifying anyone already subscribed. Listeners are NOT
   * dropped: `setupNetworkManagers()` subscribes once at module load, so
   * clearing the set would silently cut onlineManager off from every later
   * `emit()` in the same worker.
   */
  __reset() {
    mockNetInfo.emit({ isConnected: true, isInternetReachable: true });
  },
};
jest.mock('@react-native-community/netinfo', () => ({
  __esModule: true,
  default: {
    addEventListener: jest.fn((cb: (s: NetInfoSnapshot) => void) => {
      mockNetInfo.listeners.add(cb);
      cb(mockNetInfo.state);
      return () => mockNetInfo.listeners.delete(cb);
    }),
    fetch: jest.fn(async () => mockNetInfo.state),
  },
}));

// Each test starts connected, with an empty cache on "disk" and the query
// managers back at their defaults — otherwise one offline test leaves the whole
// worker offline for every suite that follows it.
beforeEach(() => {
  mockNetInfo.__reset();
  mockAsyncStorage.__reset();
  onlineManager.setOnline(true);
  focusManager.setFocused(true);
});

// expo-constants: supply the EAS projectId + version the push token registration
// reads (jest-expo's default has no expoConfig).
jest.mock('expo-constants', () => ({
  __esModule: true,
  default: { expoConfig: { version: '1.0.0', extra: { eas: { projectId: 'jest-project' } } } },
}));

// expo-notifications is native — provide the surface T-027 touches. Permission is
// granted by default; individual tests override these for the denied/skip paths.
jest.mock('expo-notifications', () => ({
  setNotificationHandler: jest.fn(),
  setNotificationChannelAsync: jest.fn(async () => {}),
  getPermissionsAsync: jest.fn(async () => ({ status: 'granted', canAskAgain: true })),
  requestPermissionsAsync: jest.fn(async () => ({ status: 'granted', canAskAgain: true })),
  getExpoPushTokenAsync: jest.fn(async () => ({ data: 'ExponentPushToken[jest]' })),
  getLastNotificationResponseAsync: jest.fn(async () => null),
  addNotificationReceivedListener: jest.fn(() => ({ remove: jest.fn() })),
  addNotificationResponseReceivedListener: jest.fn(() => ({ remove: jest.fn() })),
  AndroidImportance: { MAX: 5 },
}));

// expo-location is native (T-100). Default: permission granted, a fix available
// — so the map's happy path is the default. Tests override these per case for
// the denied / blocked / no-fix rungs of the fallback chain.
jest.mock('expo-location', () => ({
  PermissionStatus: { GRANTED: 'granted', DENIED: 'denied', UNDETERMINED: 'undetermined' },
  Accuracy: { Balanced: 3 },
  getForegroundPermissionsAsync: jest.fn(async () => ({ status: 'granted', canAskAgain: true })),
  requestForegroundPermissionsAsync: jest.fn(async () => ({ status: 'granted', canAskAgain: true })),
  getLastKnownPositionAsync: jest.fn(async () => null),
  getCurrentPositionAsync: jest.fn(async () => ({ coords: { latitude: 40.4168, longitude: -3.7038 } })),
  // The fresh-fix path watches (the only cancellable one-shot — see location.ts).
  // Default: deliver one fix immediately, and hand back a subscription whose
  // `remove` is a spy so tests can assert the watch is always torn down.
  watchPositionAsync: jest.fn(
    async (_options: unknown, callback: (l: { coords: { latitude: number; longitude: number } }) => void) => {
      callback({ coords: { latitude: 40.4168, longitude: -3.7038 } });
      return { remove: jest.fn() };
    },
  ),
}));

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
  // Current path returned by usePathname — set in a test's beforeEach.
  pathname: '' as string,
};
jest.mock('expo-router', () => {
  const React = require('react');
  return {
    router: mockRouter,
    useRouter: () => mockRouter,
    useSegments: () => [],
    usePathname: () => mockRouter.pathname,
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
        Screen: ({ name, options }: { name: string; options?: { href?: string | null } }) => {
          // `href: null` hides a route from the tab bar (still routable) — don't
          // count it as a visible tab.
          if (options?.href !== null) mockRouter.tabNames.push(name);
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
  const { View, Pressable } = require('react-native');
  return {
    GestureHandlerRootView: ({ children, style }: { children?: React.ReactNode; style?: unknown }) =>
      React.createElement(View, { style }, children),
    // Feed-card swipe-to-hide: render just the row content (the revealed action
    // is exercised on-device); the ⋯/eye-off button inside `children` remains
    // testable. React.forwardRef so the component's swipeRef stays valid.
    Swipeable: React.forwardRef(({ children }: { children?: React.ReactNode }, _ref: unknown) =>
      React.createElement(React.Fragment, null, children),
    ),
    RectButton: ({ children, ...props }: { children?: React.ReactNode }) =>
      React.createElement(Pressable, props, children),
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
  // Persistent spy (shared across renders) so tests can assert imperative map
  // moves — cluster tap, quick-share fly-to, reset view. Clear it per test.
  const animateToRegion = jest.fn();
  const MapView = Object.assign(
    React.forwardRef(({ children, ...props }: { children?: React.ReactNode }, ref: unknown) => {
      React.useImperativeHandle(ref, () => ({ animateToRegion }));
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
    __animateToRegion: animateToRegion,
  };
});

// @shopify/flash-list is native — render a lightweight list that maps data
// through renderItem (+ header/footer/empty) so feed/search screens mount and
// assert on rows in jest.
jest.mock('@shopify/flash-list', () => {
  const React = require('react');
  const { View, Pressable } = require('react-native');
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const resolve = (node: any) => (typeof node === 'function' ? node() : node);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const FlashList = (props: any) => {
    const { data = [], renderItem, keyExtractor, ListHeaderComponent, ListFooterComponent, ListEmptyComponent, onEndReached } = props;
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
      // Test hook: pressing this invokes onEndReached (the native list fires it
      // on scroll-to-bottom, which jest can't drive).
      onEndReached
        ? React.createElement(Pressable, { testID: 'flash-list-end', onPress: () => onEndReached() })
        : null,
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

// expo-camera is native (T-047). `CameraView` renders as a plain View that
// exposes its `onBarcodeScanned` through a testID, so a suite can fire a scan
// without a camera — the scanner's duplicate-read lock is the behaviour worth
// pinning and it is unreachable otherwise.
// Mutable so a test can exercise the denied / permanently-blocked branches —
// the manual-entry fallback only matters when the camera is unavailable.
export const mockCameraPermission = {
  state: { granted: true, canAskAgain: true },
  request: jest.fn(),
};
jest.mock('expo-camera', () => {
  const React = require('react');
  const { View } = require('react-native');

  return {
    CameraView: ({ children, ...props }: { children?: React.ReactNode; testID?: string }) =>
      React.createElement(View, { ...props, testID: props.testID ?? 'CameraView' }, children),
    useCameraPermissions: () => [mockCameraPermission.state, mockCameraPermission.request],
  };
});

// expo-brightness is native. Every call is best-effort in the app (a failure
// must never break the code screen), so resolving is the honest default.
jest.mock('expo-brightness', () => ({
  getBrightnessAsync: jest.fn(async () => 0.4),
  setBrightnessAsync: jest.fn(async () => undefined),
  restoreSystemBrightnessAsync: jest.fn(async () => undefined),
}));

// react-native-qrcode-svg draws through react-native-svg — native, and the
// pixels are not what any assertion here is about.
jest.mock('react-native-qrcode-svg', () => {
  const React = require('react');
  const { View } = require('react-native');

  return {
    __esModule: true,
    default: (props: { testID?: string }) => React.createElement(View, { ...props, testID: 'QRCode' }),
  };
});

// `useIsFocused` needs a navigation container the render helpers don't mount.
// Only the focus signal is stubbed — the rest of the module (ThemeProvider,
// used by the root layout) stays real, so a test rendering the layout is
// unaffected. Screens read it to gate polling and the brightness boost; in a
// unit test the screen under test is always the focused one.
jest.mock('@react-navigation/native', () => ({
  ...jest.requireActual('@react-navigation/native'),
  useIsFocused: () => true,
}));
