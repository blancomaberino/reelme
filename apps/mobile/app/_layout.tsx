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
import { extractUrl } from '@/api/shares';
import { clearToken, getToken } from '@/api/token';
import { ErrorBoundary } from '@/components/error-boundary';
import { initCrashReporting } from '@/lib/crash-reporting';
import { usePushNotifications } from '@/notifications/use-push-notifications';
import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';
import { useUiStore } from '@/stores/ui';

// Keep the splash up until the token check resolves (no login/tab flash).
SplashScreen.preventAutoHideAsync();

// Wire crash reporting once, at module load — a no-op unless a DSN is configured.
initCrashReporting();

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
              <ErrorBoundary>
                <AuthBootstrap />
                <ShareIntentRedirect />
                <PushBridge />
                <Stack screenOptions={{ headerShown: false }}>
                  <Stack.Screen name="index" />
                  <Stack.Screen name="(auth)" />
                  <Stack.Screen name="(main)" />
                  <Stack.Screen name="place/[slug]" />
                  <Stack.Screen name="shares/index" />
                  <Stack.Screen name="shares/[id]/status" />
                  <Stack.Screen name="shares/[id]/review" />
                  <Stack.Screen name="tag/[slug]" />
                  <Stack.Screen name="settings" />
                  <Stack.Screen name="profile/edit" />
                  <Stack.Screen name="lists/index" />
                  <Stack.Screen name="lists/[id]" />
                  <Stack.Screen name="list/[slug]" />
                  <Stack.Screen name="users/[username]/index" />
                  <Stack.Screen name="users/[username]/followers" />
                  <Stack.Screen name="users/[username]/following" />
                  <Stack.Screen name="invite" />
                </Stack>
              </ErrorBoundary>
            </ThemeProvider>
          </SafeAreaProvider>
        </GestureHandlerRootView>
      </QueryClientProvider>
    </ShareIntentProvider>
  );
}

/**
 * When a link/text is shared into Reelmap from another app (e.g. Instagram),
 * stage the payload and route to the ingest screen. The payload is staged in
 * `useUiStore` *before* any auth redirect so an unauthenticated share is never
 * lost: a guest is sent to sign-in (which shows a "sign in to add this place"
 * banner) and the share resumes on the ingest screen post-login. Resetting the
 * native intent after staging stops it re-firing on a later resume.
 */
function ShareIntentRedirect() {
  const { hasShareIntent, shareIntent, resetShareIntent } = useShareIntentContext();
  // Wait until the auth gate resolves so the entry Redirect (index.tsx) has run
  // and the navigator is mounted — otherwise this replace fires before the tree
  // is ready and the entry redirect clobbers it.
  const status = useSessionStore((s) => s.status);

  useEffect(() => {
    if (!hasShareIntent || status === 'loading') return;
    const text = shareIntent.text ?? '';
    const url = shareIntent.webUrl ?? extractUrl(text) ?? '';
    // An incoming `reelmap://` scheme URL is an in-app deep link (a push
    // notification target like /shares/:id/status, a shared-list link), NOT a
    // shared post — expo-share-intent captures every scheme open. Ignore it so
    // expo-router routes it normally instead of bouncing the user to the composer
    // (T-098). Check the raw intent fields (extractUrl only pulls http(s) links).
    const scheme = /^reelmap:\/\//i;
    if (scheme.test(shareIntent.webUrl ?? '') || scheme.test(text)) {
      resetShareIntent();
      return;
    }
    useUiStore.getState().setPendingShare({ url, text });
    resetShareIntent();
    // Authed → straight to ingest; guest → sign-in, which reads the staged
    // share for its banner and resumes after login.
    router.replace(status === 'authed' ? '/(main)/share' : '/(auth)/login');
  }, [hasShareIntent, shareIntent, resetShareIntent, status]);

  return null;
}

/**
 * Push-notification wiring (T-027) — lives inside the providers so it can use the
 * QueryClient (foreground live-update) and the router (tap → deep-link). Renders
 * nothing; all effects live in the hook.
 */
function PushBridge() {
  usePushNotifications();
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
