import { DarkTheme, DefaultTheme, ThemeProvider } from '@react-navigation/native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { ShareIntentProvider, useShareIntentContext } from 'expo-share-intent';
import { useEffect } from 'react';
import { StyleSheet, useColorScheme } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { fetchMe } from '@/api/hooks/useMe';
import { clearToken, getToken } from '@/api/token';
import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';

// Keep the splash up until the token check resolves (no login/tab flash).
SplashScreen.preventAutoHideAsync();

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 60_000, retry: 2 },
    mutations: { retry: 0 },
  },
});

export default function RootLayout() {
  const colorScheme = useColorScheme();

  return (
    <ShareIntentProvider options={{ debug: false, resetOnBackground: false }}>
      <QueryClientProvider client={queryClient}>
        <GestureHandlerRootView style={styles.root}>
          <SafeAreaProvider>
            <ThemeProvider value={colorScheme === 'dark' ? DarkTheme : DefaultTheme}>
              <AuthBootstrap />
              <ShareIntentRedirect />
              <Stack screenOptions={{ headerShown: false }}>
                <Stack.Screen name="index" />
                <Stack.Screen name="(auth)" />
                <Stack.Screen name="(main)" />
                <Stack.Screen name="place/[slug]" />
                <Stack.Screen name="tag/[slug]" />
                <Stack.Screen name="search" options={{ presentation: 'modal' }} />
                <Stack.Screen name="settings" />
                <Stack.Screen name="profile/edit" />
                <Stack.Screen name="lists/index" />
                <Stack.Screen name="lists/[id]" />
              </Stack>
            </ThemeProvider>
          </SafeAreaProvider>
        </GestureHandlerRootView>
      </QueryClientProvider>
    </ShareIntentProvider>
  );
}

/**
 * When a link/text is shared into Reelmap from another app (e.g. Instagram),
 * route to the Share tab with the payload prefilled, then clear the intent so a
 * later navigation back doesn't re-fire it. Only authed viewers can ingest, so
 * unauthenticated shares still land on Share (which the auth gate guards).
 */
function ShareIntentRedirect() {
  const { hasShareIntent, shareIntent, resetShareIntent } = useShareIntentContext();
  // Wait until the auth gate resolves so the entry Redirect (index.tsx) has run
  // and the navigator is mounted — otherwise this replace fires before the tree
  // is ready and the entry redirect clobbers it.
  const ready = useSessionStore((s) => s.status !== 'loading');

  useEffect(() => {
    if (!hasShareIntent || !ready) return;
    router.replace({
      pathname: '/(main)/share',
      params: { sharedUrl: shareIntent.webUrl ?? '', sharedText: shareIntent.text ?? '' },
    });
    resetShareIntent();
  }, [hasShareIntent, shareIntent, resetShareIntent, ready]);

  return null;
}

const styles = StyleSheet.create({ root: { flex: 1 } });

/**
 * Auth gate: read the token, hydrate the session via GET /me, then reveal the UI.
 * Redirect happens in app/index.tsx based on the resolved status.
 */
function AuthBootstrap() {
  const status = useSessionStore((s) => s.status);
  const setUser = useSessionStore((s) => s.setUser);
  const clear = useSessionStore((s) => s.clear);

  // Apply the saved language before the first screens paint (Spanish default).
  useEffect(() => {
    void useSettingsStore.getState().hydrate();
  }, []);

  useEffect(() => {
    let active = true;
    (async () => {
      const token = await getToken();
      if (!active) return;
      if (!token) {
        clear();
        return;
      }
      try {
        const me = await fetchMe();
        if (active) setUser(me);
      } catch {
        await clearToken();
        if (active) clear();
      }
    })();
    return () => {
      active = false;
    };
  }, [clear, setUser]);

  useEffect(() => {
    if (status !== 'loading') {
      void SplashScreen.hideAsync();
    }
  }, [status]);

  return null;
}
