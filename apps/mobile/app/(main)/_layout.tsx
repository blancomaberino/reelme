import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';

import { useT } from '@/i18n';
import { useColors } from '@/theme/colors';

/**
 * Bottom tab navigator (05-mobile-app §1.2). Map is the initial tab; Search
 * (T-077) sits where the old "Compartir" tab was — adding a place now lives on
 * the map's "+" quick-add, so the dedicated Share screen stays a route (the iOS
 * share-intent target) but is hidden from the tab bar (`href: null`). A
 * conditional Wallet tab (influencer-only) is added in M4 (T-046). Active tint
 * is the MERCADO terracotta.
 */
export default function MainTabsLayout() {
  const c = useColors();
  const t = useT();
  return (
    <Tabs
      initialRouteName="map"
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: c.primary,
        tabBarInactiveTintColor: c.muted,
        tabBarStyle: { backgroundColor: c.surface, borderTopColor: c.border },
      }}
    >
      <Tabs.Screen
        name="map"
        options={{ title: t('tabs.map'), tabBarIcon: ({ color, size }) => <Ionicons name="map" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="places"
        options={{ title: t('tabs.myPlaces'), tabBarIcon: ({ color, size }) => <Ionicons name="bookmark" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="search"
        options={{ title: t('tabs.search'), tabBarIcon: ({ color, size }) => <Ionicons name="search" color={color} size={size} /> }}
      />
      {/* Adding a place moved to the map "+" quick-add; the Share screen remains
          a route (iOS share-intent target) but is off the tab bar. */}
      <Tabs.Screen name="share" options={{ href: null }} />
      <Tabs.Screen
        name="profile"
        options={{ title: t('tabs.profile'), tabBarIcon: ({ color, size }) => <Ionicons name="person" color={color} size={size} /> }}
      />
    </Tabs>
  );
}
