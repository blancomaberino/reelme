import type { ConfigContext, ExpoConfig } from 'expo/config';

// Dev/prod variants share a codebase but install side-by-side (distinct bundle IDs).
const IS_DEV = process.env.APP_VARIANT === 'development';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: IS_DEV ? 'Reelmap (Dev)' : 'Reelmap',
  slug: 'reelmap',
  scheme: 'reelmap',
  owner: 'mindastic',
  version: '1.0.0',
  orientation: 'portrait',
  icon: './assets/images/icon.png',
  userInterfaceStyle: 'automatic',
  ios: {
    bundleIdentifier: IS_DEV ? 'pet.one.reelmap.dev' : 'pet.one.reelmap',
    icon: './assets/expo.icon',
    supportsTablet: true,
    config: { usesNonExemptEncryption: false },
  },
  android: {
    package: IS_DEV ? 'pet.one.reelmap.dev' : 'pet.one.reelmap',
    // react-native-maps on Android is Google Maps and needs an API key (iOS uses
    // Apple Maps, no key). Set GOOGLE_MAPS_ANDROID_KEY to render the map on
    // Android; omitted (no-op) when unset so builds still work without it.
    ...(process.env.GOOGLE_MAPS_ANDROID_KEY
      ? { config: { googleMaps: { apiKey: process.env.GOOGLE_MAPS_ANDROID_KEY } } }
      : {}),
    adaptiveIcon: {
      backgroundColor: '#E6F4FE',
      foregroundImage: './assets/images/android-icon-foreground.png',
      backgroundImage: './assets/images/android-icon-background.png',
      monochromeImage: './assets/images/android-icon-monochrome.png',
    },
    predictiveBackGestureEnabled: false,
  },
  web: { output: 'static', favicon: './assets/images/favicon.png' },
  extra: {
    apiUrl: process.env.EXPO_PUBLIC_API_URL,
    // EAS project @mindastic/reelmap. Set manually because eas-cli 20.5's config
    // writer can't modify a TS config under TypeScript 6.0 (it reads fine).
    eas: { projectId: process.env.EAS_PROJECT_ID ?? '4d05e4d7-cfac-45d0-afbd-22ae34f69e32' },
  },
  updates: { url: 'https://u.expo.dev/4d05e4d7-cfac-45d0-afbd-22ae34f69e32' },
  runtimeVersion: { policy: 'appVersion' },
  plugins: [
    'expo-router',
    [
      'expo-splash-screen',
      { backgroundColor: '#208AEF', image: './assets/images/splash-icon.png', imageWidth: 76 },
    ],
    'expo-secure-store',
    'expo-notifications',
    // Share extension (T-025): receive links/text shared from other apps (e.g.
    // Instagram) into Reelmap. iOS app group defaults to group.<bundleId>.
    [
      'expo-share-intent',
      {
        iosActivationRules: {
          NSExtensionActivationSupportsWebURLWithMaxCount: 1,
          NSExtensionActivationSupportsWebPageWithMaxCount: 1,
          NSExtensionActivationSupportsText: true,
        },
      },
    ],
  ],
  experiments: { typedRoutes: true, reactCompiler: true },
});
