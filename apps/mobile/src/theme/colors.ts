// Central palette. Two schemes (light/dark) resolved from the OS setting via
// `useColors()`. Components build their StyleSheet through a `makeStyles(c)`
// factory so a scheme switch re-themes without remounting.
import { useColorScheme } from 'react-native';

export type Palette = {
  /** Brand accent — primary buttons, links, focus rings. */
  primary: string;
  /** Pressed/active shade of the accent. */
  primaryPressed: string;
  /** Tint behind the welcome logo mark. */
  primarySoft: string;
  danger: string;
  /** Screen background. */
  background: string;
  /** Raised surfaces — inputs, cards. */
  surface: string;
  /** Primary body text. */
  text: string;
  /** Secondary text — captions, helper copy. */
  muted: string;
  placeholder: string;
  border: string;
  /** Text that sits on top of the accent color. */
  onPrimary: string;
};

const light: Palette = {
  primary: '#208AEF',
  primaryPressed: '#1B74C9',
  primarySoft: '#E6F1FD',
  danger: '#DC2626',
  background: '#FFFFFF',
  surface: '#FFFFFF',
  text: '#111827',
  muted: '#6B7280',
  placeholder: '#9CA3AF',
  border: '#D8DDE4',
  onPrimary: '#FFFFFF',
};

const dark: Palette = {
  primary: '#4DA3FF',
  primaryPressed: '#3B8AE6',
  primarySoft: '#12243A',
  danger: '#F87171',
  background: '#0C1116',
  surface: '#161C24',
  text: '#F3F4F6',
  muted: '#9AA4B2',
  placeholder: '#5C6675',
  border: '#2A323D',
  onPrimary: '#FFFFFF',
};

export function useColors(): Palette {
  return useColorScheme() === 'dark' ? dark : light;
}
