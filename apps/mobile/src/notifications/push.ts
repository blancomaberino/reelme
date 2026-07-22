import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Alert, Platform } from 'react-native';

import { api } from '@/api/client';

import { isOnTargetRoute } from './routing';

// The route the user is currently viewing, mirrored here by the hook (via
// usePathname) so the module-level foreground handler can suppress a banner for
// the screen they're already on without threading React state into it.
let currentPath: string | null = null;

export function setCurrentPath(path: string | null): void {
  currentPath = path;
}

/**
 * Foreground presentation: show a banner + play a sound EXCEPT when the incoming
 * notification targets the screen the user is already on (05 §5.2). Registered
 * once at startup.
 */
export function configureForegroundHandler(): void {
  Notifications.setNotificationHandler({
    handleNotification: async (notification) => {
      const url = notification.request.content.data?.url;
      const suppress = isOnTargetRoute(typeof url === 'string' ? url : null, currentPath);
      return {
        shouldShowBanner: !suppress,
        shouldShowList: true,
        shouldPlaySound: !suppress,
        shouldSetBadge: false,
      };
    },
  });
}

/**
 * Android 8+ shows nothing without a channel — create the `default` MAX-importance
 * channel before any notification can arrive. No-op on iOS.
 */
export async function setupAndroidChannel(): Promise<void> {
  if (Platform.OS !== 'android') return;
  try {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'Default',
      importance: Notifications.AndroidImportance.MAX,
    });
  } catch {
    // best-effort: a channel-setup failure must not crash startup
  }
}

function projectId(): string | undefined {
  const extra = Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined;
  return extra?.eas?.projectId;
}

async function currentToken(): Promise<string> {
  const pid = projectId();
  // The EAS projectId is mandatory in dev builds — omitting it is the classic
  // "works in prod, throws in dev" bug (T-027 gotcha).
  const { data } = await Notifications.getExpoPushTokenAsync(pid ? { projectId: pid } : undefined);
  return data;
}

/** Default soft pre-prompt: explain the value before the one-shot OS prompt. */
function defaultConfirm(): Promise<boolean> {
  return new Promise((resolve) => {
    Alert.alert(
      'Avisos de tus lugares',
      'Te avisamos cuando terminamos de analizar un enlace que compartiste, para que lo confirmes o lo veas en tu mapa.',
      [
        { text: 'Ahora no', style: 'cancel', onPress: () => resolve(false) },
        { text: 'Activar', onPress: () => resolve(true) },
      ],
    );
  });
}

/**
 * Register this install's Expo push token for the authed user (05 §5.1). No-op on
 * a simulator/emulator (no real APNs/FCM token). Requests permission behind a
 * soft pre-prompt so a declined user doesn't burn the one-shot iOS system prompt;
 * an already-granted user re-registers silently (tokens rotate, and the row must
 * follow the current user on a shared device).
 */
export async function registerForPush(confirm: () => Promise<boolean> = defaultConfirm): Promise<void> {
  if (!Device.isDevice) return;

  // Whole flow is best-effort: a rejection from the permission APIs, the token
  // fetch (the dev projectId gotcha), or the POST must never crash the app —
  // the user keeps everything else, and the next authed launch retries.
  try {
    const { status, canAskAgain } = await Notifications.getPermissionsAsync();
    let granted = status === 'granted';

    if (!granted && canAskAgain) {
      if (!(await confirm())) return; // user declined the soft pre-prompt
      granted = (await Notifications.requestPermissionsAsync()).status === 'granted';
    }
    if (!granted) return;

    const token = await currentToken();
    await api.post('/devices', {
      token,
      platform: Platform.OS === 'android' ? 'android' : 'ios',
      device_name: Device.deviceName ?? undefined,
      app_version: Constants.expoConfig?.version ?? undefined,
    });
  } catch {
    // best-effort — see above
  }
}

/**
 * Unregister this install's token on logout. Must run while the bearer token is
 * still valid (the DELETE is authed). Best-effort — a failure is harmless because
 * the server also prunes dead tokens from Expo receipts.
 */
export async function unregisterPush(): Promise<void> {
  if (!Device.isDevice) return;
  try {
    const token = await currentToken();
    await api.delete(`/devices/${encodeURIComponent(token)}`);
  } catch {
    // ignore — token cleanup is best-effort
  }
}
