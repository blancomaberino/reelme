// axios exposes create/interceptors as members of the default export.
/* eslint-disable import/no-named-as-default-member */
import axios, { AxiosError, AxiosHeaders } from 'axios';
import { router } from 'expo-router';

import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';
import { useUiStore } from '@/stores/ui';

import { clearToken, getToken } from './token';
import { EmailNotVerifiedError, ValidationError, type FieldErrors } from './types';

type ApiErrorEnvelope = {
  error?: { code?: string; message?: string; details?: Record<string, string[] | string> };
};

const baseURL = `${process.env.EXPO_PUBLIC_API_URL ?? ''}/api/v1`;

export const api = axios.create({
  baseURL,
  headers: { Accept: 'application/json' },
});

// Attach the bearer token ONLY to same-origin API calls (relative URLs). An
// absolute URL would override baseURL and leak the token to another host.
// The pattern mirrors axios's own isAbsoluteURL: the scheme is optional, so a
// protocol-relative `//host` also counts as absolute (and skips baseURL).
api.interceptors.request.use(async (config) => {
  const isRelative = !/^([a-z][a-z\d+\-.]*:)?\/\//i.test(config.url ?? '');
  if (isRelative) {
    const headers = AxiosHeaders.from(config.headers);
    // Tells the API which locale to localize tag labels (etc.) to (ADR-084 #2).
    headers.set('Accept-Language', useSettingsStore.getState().locale);
    const token = await getToken();
    if (token) {
      headers.set('Authorization', `Bearer ${token}`);
    }
    config.headers = headers;
  }
  return config;
});

api.interceptors.response.use(
  (response) => {
    // A successful request means we're no longer throttled — clear the sticky flag.
    if (useUiStore.getState().rateLimited) {
      useUiStore.getState().setRateLimited(false);
    }
    return response;
  },
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
      // Capture before clear() flips the status to 'guest'.
      const bootstrapping = useSessionStore.getState().status === 'loading';
      await clearToken();
      useSessionStore.getState().clear();
      // During bootstrap the root-layout gate + index own navigation; redirecting
      // here would race them (login vs welcome). Only redirect a live session.
      if (!bootstrapping) {
        router.replace('/(auth)/login');
      }
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

    // Unconfirmed email at login (T-066): surface a typed error so the screen
    // can route to the verify flow prefilled with the account's email.
    if (status === 403 && error.response?.data?.error?.code === 'email_not_verified') {
      const email = String(error.response?.data?.error?.details?.email ?? '');
      return Promise.reject(new EmailNotVerifiedError(email));
    }

    return Promise.reject(error);
  },
);
