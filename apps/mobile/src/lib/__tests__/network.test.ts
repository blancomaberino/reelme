import { focusManager, onlineManager } from '@tanstack/react-query';
import { AppState } from 'react-native';

import { isOnline, setupNetworkManagers } from '@/lib/network';
import { useUiStore } from '@/stores/ui';

import { mockNetInfo } from '../../../jest.setup';

/**
 * Without this wiring React Query believes a device is permanently online and
 * permanently focused (its defaults listen for browser events), so every query
 * burns its retries on a dead network instead of parking (T-103).
 */
describe('setupNetworkManagers', () => {
  let appStateListener: ((status: string) => void) | undefined;

  beforeEach(() => {
    appStateListener = undefined;
    jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, handler) => {
      appStateListener = handler as (status: string) => void;
      return { remove: jest.fn() } as never;
    });
    setupNetworkManagers();
    useUiStore.setState({ offline: false });
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('takes React Query offline when NetInfo loses the connection, and back on reconnect', () => {
    expect(onlineManager.isOnline()).toBe(true);

    mockNetInfo.emit({ isConnected: false, isInternetReachable: false });
    expect(onlineManager.isOnline()).toBe(false);

    mockNetInfo.emit({ isConnected: true, isInternetReachable: true });
    expect(onlineManager.isOnline()).toBe(true);
  });

  it('mirrors connectivity into the UI store so the banner can react', () => {
    mockNetInfo.emit({ isConnected: false, isInternetReachable: false });
    expect(useUiStore.getState().offline).toBe(true);

    mockNetInfo.emit({ isConnected: true, isInternetReachable: true });
    expect(useUiStore.getState().offline).toBe(false);
  });

  it('counts a connection whose reachability is still unknown as online', () => {
    // NetInfo reports `null` until its first probe resolves — flashing the
    // banner for that beat on every cold start would be worse than optimism.
    mockNetInfo.emit({ isConnected: true, isInternetReachable: null });

    expect(onlineManager.isOnline()).toBe(true);
    expect(useUiStore.getState().offline).toBe(false);
  });

  it('treats a backgrounded app as unfocused so queries do not refetch behind it', () => {
    expect(appStateListener).toBeDefined();

    appStateListener!('background');
    expect(focusManager.isFocused()).toBe(false);

    appStateListener!('active');
    expect(focusManager.isFocused()).toBe(true);
  });
});

describe('isOnline', () => {
  it.each([
    [{ isConnected: true, isInternetReachable: true }, true],
    [{ isConnected: true, isInternetReachable: null }, true],
    [{ isConnected: true, isInternetReachable: false }, false],
    [{ isConnected: false, isInternetReachable: null }, false],
    [{ isConnected: null, isInternetReachable: null }, true],
  ])('%j -> %s', (state, expected) => {
    expect(isOnline(state)).toBe(expected);
  });
});
