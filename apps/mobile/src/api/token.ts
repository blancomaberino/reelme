import * as SecureStore from 'expo-secure-store';

// The Sanctum bearer token lives ONLY in SecureStore (never AsyncStorage/zustand).
// A memory cache avoids an async read on every request; it's refreshed on set/clear.
const KEY = 'api_token';

let cache: string | null = null;
let loaded = false;

export async function getToken(): Promise<string | null> {
  if (!loaded) {
    cache = await SecureStore.getItemAsync(KEY);
    loaded = true;
  }
  return cache;
}

export async function setToken(token: string): Promise<void> {
  cache = token;
  loaded = true;
  await SecureStore.setItemAsync(KEY, token);
}

export async function clearToken(): Promise<void> {
  cache = null;
  loaded = true;
  await SecureStore.deleteItemAsync(KEY);
}
