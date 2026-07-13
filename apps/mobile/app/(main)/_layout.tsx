import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';

import { useColors } from '@/theme/colors';

/**
 * Bottom tab navigator (05-mobile-app §1.2). Map is the initial tab; Share is
 * the prominent center "+" tab. A conditional Wallet tab (influencer-only) is
 * added in M4 (T-046). Active tint is the MERCADO terracotta.
 */
export default function MainTabsLayout() {
  const c = useColors();
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
        options={{ title: 'Map', tabBarIcon: ({ color, size }) => <Ionicons name="map" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="feed"
        options={{ title: 'Feed', tabBarIcon: ({ color, size }) => <Ionicons name="albums" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="share"
        options={{ title: 'Share', tabBarIcon: ({ color, size }) => <Ionicons name="add-circle" color={color} size={size + 8} /> }}
      />
      <Tabs.Screen
        name="profile"
        options={{ title: 'Profile', tabBarIcon: ({ color, size }) => <Ionicons name="person" color={color} size={size} /> }}
      />
    </Tabs>
  );
}
