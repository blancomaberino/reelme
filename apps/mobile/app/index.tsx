import { Redirect } from 'expo-router';

import { useSessionStore } from '@/stores/session';

/** Route the app based on the resolved auth status (splash covers `loading`). */
export default function Index() {
  const status = useSessionStore((s) => s.status);

  if (status === 'loading') {
    return null;
  }

  return <Redirect href={status === 'authed' ? '/(main)/map' : '/(auth)/welcome'} />;
}
