import AxiosMockAdapter from 'axios-mock-adapter';
import * as Notifications from 'expo-notifications';

import { api } from '@/api/client';

import { configureForegroundHandler, registerForPush, setCurrentPath, unregisterPush } from '../push';

let mock: AxiosMockAdapter;

beforeEach(() => {
  mock = new AxiosMockAdapter(api);
  jest.clearAllMocks();
  setCurrentPath(null);
  // Default: permission already granted (overridden per-test for other paths).
  (Notifications.getPermissionsAsync as jest.Mock).mockResolvedValue({ status: 'granted', canAskAgain: true });
});
afterEach(() => mock.restore());

describe('registerForPush', () => {
  it('posts the Expo token + platform + version for an already-granted device', async () => {
    let sent: Record<string, unknown> = {};
    mock.onPost('/devices').reply((cfg) => {
      sent = JSON.parse(cfg.data);
      return [201, { data: { id: 1, platform: 'ios' } }];
    });

    const confirm = jest.fn(async () => true);
    await registerForPush(confirm);

    // Already granted → no soft pre-prompt, no system prompt.
    expect(confirm).not.toHaveBeenCalled();
    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();
    expect(Notifications.getExpoPushTokenAsync).toHaveBeenCalledWith({ projectId: 'jest-project' });
    expect(sent.token).toBe('ExponentPushToken[jest]');
    expect(sent.platform).toBe('ios');
    expect(sent.app_version).toBe('1.0.0');
  });

  it('asks via the soft pre-prompt then the OS when permission is undetermined', async () => {
    (Notifications.getPermissionsAsync as jest.Mock).mockResolvedValue({ status: 'undetermined', canAskAgain: true });
    let posted = false;
    mock.onPost('/devices').reply(() => {
      posted = true;
      return [201, { data: { id: 1, platform: 'ios' } }];
    });

    await registerForPush(jest.fn(async () => true));

    expect(Notifications.requestPermissionsAsync).toHaveBeenCalled();
    expect(posted).toBe(true);
  });

  it('does not burn the OS prompt when the user declines the soft pre-prompt', async () => {
    (Notifications.getPermissionsAsync as jest.Mock).mockResolvedValue({ status: 'undetermined', canAskAgain: true });
    let posted = false;
    mock.onPost('/devices').reply(() => {
      posted = true;
      return [201, {}];
    });

    await registerForPush(jest.fn(async () => false));

    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();
    expect(posted).toBe(false);
  });

  it('never throws when the /devices POST fails (best-effort registration)', async () => {
    mock.onPost('/devices').reply(500);
    await expect(registerForPush(jest.fn(async () => true))).resolves.toBeUndefined();
  });

  it('registers nothing when the OS permission is denied', async () => {
    (Notifications.getPermissionsAsync as jest.Mock).mockResolvedValue({ status: 'undetermined', canAskAgain: true });
    (Notifications.requestPermissionsAsync as jest.Mock).mockResolvedValue({ status: 'denied' });
    let posted = false;
    mock.onPost('/devices').reply(() => {
      posted = true;
      return [201, {}];
    });

    await registerForPush(jest.fn(async () => true));

    expect(posted).toBe(false);
  });
});

describe('unregisterPush', () => {
  it('deletes the device by its token', async () => {
    let deletedUrl = '';
    mock.onDelete(/\/devices\/.+/).reply((cfg) => {
      deletedUrl = cfg.url ?? '';
      return [204];
    });

    await unregisterPush();

    expect(deletedUrl).toBe('/devices/ExponentPushToken%5Bjest%5D');
  });

  it('never throws when the delete fails (best-effort cleanup)', async () => {
    mock.onDelete(/\/devices\/.+/).reply(500);
    await expect(unregisterPush()).resolves.toBeUndefined();
  });
});

describe('configureForegroundHandler', () => {
  it('suppresses the banner on the target route and shows it elsewhere', async () => {
    configureForegroundHandler();
    const handler = (Notifications.setNotificationHandler as jest.Mock).mock.calls[0][0].handleNotification;
    const notif = (url: string) => ({ request: { content: { data: { url } } } });

    setCurrentPath('/shares/7/status');
    await expect(handler(notif('/shares/7/status'))).resolves.toMatchObject({ shouldShowBanner: false });

    setCurrentPath('/(main)/map');
    await expect(handler(notif('/shares/7/status'))).resolves.toMatchObject({ shouldShowBanner: true });
  });
});
