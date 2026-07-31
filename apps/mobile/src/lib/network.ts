import NetInfo, { type NetInfoState } from '@react-native-community/netinfo';
import { focusManager, onlineManager } from '@tanstack/react-query';
import { AppState, type AppStateStatus } from 'react-native';

import { useUiStore } from '@/stores/ui';

/**
 * Connectivity + focus wiring for React Query (T-103).
 *
 * React Query ships web defaults: `onlineManager` listens to `window.online`
 * and `focusManager` to `visibilitychange`, neither of which exists on a
 * device. Without this the client believes it is permanently online and
 * permanently focused, so a subway ride burns `retry: 2` on every query
 * instead of parking it — and coming back from the background never refetches.
 *
 * With the managers wired, a query with no cached data goes to
 * `fetchStatus: 'paused'` while offline (the signal the UI uses to tell
 * "offline" apart from "failed" and "genuinely empty") and resumes on its own
 * when the connection returns.
 */

/**
 * NetInfo reports `isInternetReachable: null` until its first reachability
 * probe resolves. Treat unknown as online — flashing an offline banner during
 * the first second of every cold start is worse than a beat of optimism.
 */
export function isOnline(state: Pick<NetInfoState, 'isConnected' | 'isInternetReachable'>): boolean {
  return state.isConnected !== false && state.isInternetReachable !== false;
}

/**
 * Wire `onlineManager` to NetInfo and `focusManager` to AppState, and mirror
 * connectivity into `useUiStore.offline` for the banner.
 *
 * Both native modules are absent until the dev client is rebuilt with them, and
 * a throwing module here would take the whole app down at boot — so each wiring
 * is guarded independently. Losing the listener degrades to today's behaviour
 * (assume online), it does not break the app.
 */
export function setupNetworkManagers(): void {
  try {
    onlineManager.setEventListener((setOnline) => {
      const unsubscribe = NetInfo.addEventListener((state) => {
        const online = isOnline(state);
        setOnline(online);
        useUiStore.getState().setOffline(!online);
      });
      return () => unsubscribe();
    });
  } catch {
    // NetInfo native module unavailable — stay on the "always online" default.
  }

  try {
    focusManager.setEventListener((handleFocus) => {
      const subscription = AppState.addEventListener('change', (status: AppStateStatus) => {
        handleFocus(status === 'active');
      });
      return () => subscription.remove();
    });
  } catch {
    // AppState is core RN; this only trips in an exotic test environment.
  }
}
