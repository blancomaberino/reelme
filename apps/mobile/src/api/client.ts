// axios exposes create/interceptors as members of the default export.
/* eslint-disable import/no-named-as-default-member */
import axios, { AxiosError, AxiosHeaders } from 'axios';
import { router } from 'expo-router';

import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

import { clearToken, getToken } from './token';
import { ValidationError, type FieldErrors } from './types';

type ApiErrorEnvelope = {
  error?: { message?: string; details?: Record<string, string[] | string> };
};

const baseURL = `${process.env.EXPO_PUBLIC_API_URL ?? ''}/api/v1`;

export const api = axios.create({
  baseURL,
  headers: { Accept: 'application/json' },
});

// Attach the bearer token ONLY to same-origin API calls (relative URLs). An
// absolute URL would override baseURL and leak the token to another host.
api.interceptors.request.use(async (config) => {
  const isRelative = !/^https?:\/\//i.test(config.url ?? '');
  if (isRelative) {
    const token = await getToken();
    if (token) {
      const headers = AxiosHeaders.from(config.headers);
      headers.set('Authorization', `Bearer ${token}`);
      config.headers = headers;
    }
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiErrorEnvelope>) => {
    // Scrub the bearer token from the error so it can never travel to a logger
    // or crash reporter attached later (the error object outlives this handler).
    if (error.config?.headers) {
      const headers = AxiosHeaders.from(error.config.headers);
      headers.delete('Authorization');
      error.config.headers = headers;
    }

    const status = error.response?.status;
    const url = error.config?.url ?? '';
    const isAuthPath = url.startsWith('/auth/'); // never self-trigger on login/register/logout

    if (status === 401 && !isAuthPath) {
      await clearToken();
      useSessionStore.getState().clear();
      router.replace('/(auth)/login');
    }

    if (status === 422) {
      const details = error.response?.data?.error?.details ?? {};
      const fields: FieldErrors = {};
      for (const [key, value] of Object.entries(details)) {
        fields[key] = Array.isArray(value) ? value[0] : String(value);
      }
      return Promise.reject(new ValidationError(fields, error.response?.data?.error?.message));
    }

    if (status === 429) {
      useUiStore.getState().setRateLimited(true);
    }

    return Promise.reject(error);
  },
);
