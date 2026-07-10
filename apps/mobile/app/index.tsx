import { Redirect } from 'expo-router';

/**
 * Entry route. Until the auth gate lands (T-010), boot straight into the tab
 * shell so the app is runnable.
 */
export default function Index() {
  return <Redirect href="/(main)/map" />;
}
