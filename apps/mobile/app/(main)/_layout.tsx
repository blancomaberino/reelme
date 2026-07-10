import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';

/**
 * Bottom tab navigator (05-mobile-app §1.2). Map is the initial tab; Share is
 * the prominent center "+" tab. A conditional Wallet tab (influencer-only) is
 * added in M4 (T-046).
 */
export default function MainTabsLayout() {
  return (
    <Tabs screenOptions={{ headerShown: false }} initialRouteName="map">
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
