import { DarkTheme, DefaultTheme, ThemeProvider } from '@react-navigation/native';
import { useIsRestoring } from '@tanstack/react-query';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client';
import { router, Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { ShareIntentProvider, useShareIntentContext } from 'expo-share-intent';
import { useEffect } from 'react';
import { StyleSheet, useColorScheme } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { fetchMe } from '@/api/hooks/useMe';
import { queryKeys } from '@/api/keys';
import { queryClient } from '@/api/query-client';
import { extractUrl } from '@/api/shares';
import { clearToken, getToken } from '@/api/token';
import { type Me, NetworkError } from '@/api/types';
import { ConnectionBanner } from '@/components/connection-banner';
import { ErrorBoundary } from '@/components/error-boundary';
import { initCrashReporting } from '@/lib/crash-reporting';
import { setupNetworkManagers } from '@/lib/network';
import { persistOptions } from '@/lib/query-persist';
import { usePushNotifications } from '@/notifications/use-push-notifications';
import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';
import { useUiStore } from '@/stores/ui';
import { useViewportStore } from '@/stores/viewport';

// Keep the splash up until the token check resolves (no login/tab flash).
SplashScreen.preventAutoHideAsync();

// Wire crash reporting once, at module load — a no-op unless a DSN is configured.
initCrashReporting();

// Connectivity/focus wiring (T-103) — must be in place before the QueryClient
// takes its first decision about whether to fetch or park.
setupNetworkManagers();

export default function RootLayout() {
  const colorScheme = useColorScheme();

  return (
    <ShareIntentProvider options={{ debug: false, resetOnBackground: false }}>
      {/* Restores the persisted cache before the first query runs (T-103), so a
          cold start with no network paints saved places instead of a spinner. */}
      <PersistQueryClientProvider client={queryClient} persistOptions={persistOptions}>
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
                {/* Last child so it layers over every screen, including the
                    fullscreen map, which has no header to push down. */}
                <ConnectionBanner />
              </ErrorBoundary>
            </ThemeProvider>
          </SafeAreaProvider>
        </GestureHandlerRootView>
      </PersistQueryClientProvider>
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
  // The persisted cache is still being read off disk on the first render
  // (T-103). Waiting for it means the offline fallback below can actually find
  // the cached ['me'] entry instead of racing the restore and missing it.
  const restoring = useIsRestoring();

  // Apply the saved language before the first screens paint (Spanish default),
  // and read back the remembered map viewport (T-100) so the map can open where
  // the user left it without an async gate on its own mount.
  useEffect(() => {
    void useSettingsStore.getState().hydrate();
    void useViewportStore.getState().hydrate();
  }, []);

  useEffect(() => {
    if (restoring) return;
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
        // Mirror it into the query cache as well as the session store. The
        // bootstrap fetch is imperative — it is not a useQuery — so without
        // this the ['me'] entry only ever exists after a fresh login, and a
        // returning user would have nothing persisted for the offline branch
        // below to restore. (Verified on device: with the API unreachable the
        // app dropped to the welcome screen precisely because of this.)
        queryClient.setQueryData(queryKeys.me, me);
        if (active) setUser(me);
      } catch (error) {
        // A cold start with no network must NOT sign the user out (T-103): the
        // token is still valid, we simply couldn't ask. Keep it and restore the
        // session from the persisted ['me'] entry so the app opens on the
        // user's own map.
        //
        // The restored profile stays as it was until the next launch — nothing
        // subscribes to ['me'], so there is no query to resume. That is on
        // purpose: the fields it carries are cosmetic, and the one case that
        // matters — a token revoked while we were offline — self-heals, because
        // the next real request 401s and the interceptor above ends the session.
        if (error instanceof NetworkError) {
          const cached = queryClient.getQueryData<Me>(queryKeys.me);
          if (active && cached) {
            setUser(cached);
            return;
          }
        } else {
          // A genuine rejection (401 — the interceptor has already cleared the
          // token — or a malformed response) ends the session.
          await clearToken();
        }
        if (active) clear();
      }
    })();
    return () => {
      active = false;
    };
  }, [clear, restoring, setUser]);

  useEffect(() => {
    if (status !== 'loading') {
      void SplashScreen.hideAsync();
    }
  }, [status]);

  return null;
}
