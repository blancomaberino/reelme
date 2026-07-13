import { useCallback } from 'react';

import { type Locale, useSettingsStore } from '@/stores/settings';

import { en, type MessageKey } from './en';
import { es } from './es';

const dictionaries: Record<Locale, Record<MessageKey, string>> = { en, es };

type Params = Record<string, string | number>;

function interpolate(template: string, params?: Params): string {
  if (!params) return template;
  return template.replace(/\{\{(\w+)\}\}/g, (_m, name: string) =>
    name in params ? String(params[name]) : `{{${name}}}`,
  );
}

/**
 * Translate `key` for `locale`. If `params.count` is present, resolves the
 * `${key}_one` / `${key}_other` plural variant (English cardinal rule: 1 → one).
 * Unknown keys fall through to the key itself so a missing string is visible,
 * not a crash.
 */
export function translate(locale: Locale, key: MessageKey, params?: Params): string {
  const dict = dictionaries[locale] ?? dictionaries.es;
  let resolved = key;
  if (params && typeof params.count === 'number') {
    const variant = `${key}_${params.count === 1 ? 'one' : 'other'}` as MessageKey;
    if (variant in dict) resolved = variant;
  }
  const template = dict[resolved] ?? en[resolved] ?? key;
  return interpolate(template, params);
}

/**
 * Hook form: returns a `t()` bound to the current locale and re-renders the
 * caller when the locale changes (subscribes to the settings store).
 */
export function useT() {
  const locale = useSettingsStore((s) => s.locale);
  return useCallback((key: MessageKey, params?: Params) => translate(locale, key, params), [locale]);
}

export type { MessageKey };
