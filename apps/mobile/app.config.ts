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
    // Required companion to `locales` below (Expo's localization guide): lets
    // iOS serve a per-language InfoPlist.strings override and fall back to the
    // base Info.plist value for any language we don't ship. Expo does not set
    // it for you — @expo/config-plugins only leaves a "possibly validate
    // CFBundleAllowMixedLocalizations is enabled" TODO. Applies at prebuild.
    infoPlist: { CFBundleAllowMixedLocalizations: true },
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
  // Localized permission prompts. The base strings below are Spanish (the app's
  // default locale, 05-mobile-app §1); an English-locale device gets these
  // overrides instead. Expo writes them to `en.lproj/InfoPlist.strings`.
  //
  // Given inline rather than as a file path: @expo/config-plugins' locale
  // resolver takes either ("in the off chance that someone defined the locales
  // json in the config, pass it directly"), and one prompt does not warrant a
  // second file. Nested under `ios` because the resolver hands every TOP-LEVEL
  // key to both platforms — a flat `NSLocation…` would land in Android's
  // `values-b+en/strings.xml` as a string resource nothing reads.
  locales: {
    en: {
      ios: {
        NSLocationWhenInUseUsageDescription:
          'Reelmap uses your location to open the map where you are and show places nearby.',
      },
    },
  },
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
    // Foreground location (T-100): centres the map on the user on first launch
    // and powers the "locate me" control. WHEN-IN-USE only — Reelmap never
    // tracks in the background, so both background flags stay off (they also
    // trigger extra App Store review questions we have no reason to answer).
    // The plugin writes NSLocationWhenInUseUsageDescription plus the Android
    // ACCESS_COARSE/FINE_LOCATION permissions; `locales.en` above localizes it.
    [
      'expo-location',
      {
        locationWhenInUsePermission:
          'Reelmap usa tu ubicación para abrir el mapa donde estás y mostrarte lugares cerca.',
        isIosBackgroundLocationEnabled: false,
        isAndroidBackgroundLocationEnabled: false,
        // `false` DELETES the key (see @expo/config-plugins applyPermissions) —
        // without this the plugin writes its generic defaults for all four
        // permissions, declaring always/background location and motion that we
        // never request. Keep the declared surface to exactly what we use, so
        // App Store review has nothing extra to ask about.
        locationAlwaysAndWhenInUsePermission: false,
        locationAlwaysPermission: false,
        motionUsagePermission: false,
      },
    ],
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
        // Android 12+ needs an explicit intent filter to appear in the share
        // sheet; `text/*` catches the shared link/caption (Instagram et al.
        // share the reel URL as text). No image/video rules in M1 — screen
        // recordings go through the in-app picker, and media rules would make
        // Reelmap show up in every photo share sheet.
        androidIntentFilters: ['text/*'],
      },
    ],
  ],
  experiments: { typedRoutes: true, reactCompiler: true },
});
