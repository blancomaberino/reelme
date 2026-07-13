import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';

import { useT } from '@/i18n';
import { useColors } from '@/theme/colors';

/**
 * Bottom tab navigator (05-mobile-app §1.2). Map is the initial tab; Share is
 * the prominent center "+" tab. A conditional Wallet tab (influencer-only) is
 * added in M4 (T-046). Active tint is the MERCADO terracotta.
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
        name="feed"
        options={{ title: t('tabs.feed'), tabBarIcon: ({ color, size }) => <Ionicons name="albums" color={color} size={size} /> }}
      />
      <Tabs.Screen
        name="share"
        options={{ title: t('tabs.share'), tabBarIcon: ({ color, size }) => <Ionicons name="add-circle" color={color} size={size + 8} /> }}
      />
      <Tabs.Screen
        name="profile"
        options={{ title: t('tabs.profile'), tabBarIcon: ({ color, size }) => <Ionicons name="person" color={color} size={size} /> }}
      />
    </Tabs>
  );
}
