import { DarkTheme, DefaultTheme, ThemeProvider } from '@react-navigation/native';
import { Stack } from 'expo-router';
import { useColorScheme } from 'react-native';

/**
 * Root layout. Renders the (auth) and (main) route groups.
 *
 * TODO(T-010): auth gate — read the token from SecureStore, hydrate the session
 * store via GET /me, and <Redirect> to (auth)/welcome when unauthenticated
 * (keep the splash visible until the token check resolves).
 * TODO(T-025): share-intent staging listener.
 */
export default function RootLayout() {
  const colorScheme = useColorScheme();

  return (
    <ThemeProvider value={colorScheme === 'dark' ? DarkTheme : DefaultTheme}>
      <Stack screenOptions={{ headerShown: false }}>
        <Stack.Screen name="(auth)" />
        <Stack.Screen name="(main)" />
        <Stack.Screen name="settings" options={{ headerShown: true, title: 'Settings' }} />
      </Stack>
    </ThemeProvider>
  );
}
