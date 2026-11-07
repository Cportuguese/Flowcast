import Ionicons from '@expo/vector-icons/Ionicons';
import { Tabs } from 'expo-router';
import { BRAND_FONT } from '../../components/Text';

const BRAND = '#0b1636';
const MUTED = '#94a3b8';

// The tab bar hands icons the tint it was given above, always a string; the
// newer typings just widen it to any colour value.
const asColor = (color: unknown) => (typeof color === 'string' ? color : undefined);

export default function TabLayout() {
  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: BRAND,
        tabBarInactiveTintColor: MUTED,
        tabBarStyle: { backgroundColor: '#ffffff', borderTopColor: '#e2e8f0' },
        // The semibold font file itself, not fontWeight (see components/Text.tsx).
        tabBarLabelStyle: { fontSize: 11, fontFamily: BRAND_FONT.semibold },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Home',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'home' : 'home-outline'} size={22} color={asColor(color)} />
          ),
        }}
      />
      <Tabs.Screen
        name="appointments"
        options={{
          title: 'Appointments',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'calendar' : 'calendar-outline'} size={22} color={asColor(color)} />
          ),
        }}
      />
      <Tabs.Screen
        name="visits"
        options={{
          title: 'Visits',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'document-text' : 'document-text-outline'} size={22} color={asColor(color)} />
          ),
        }}
      />
    </Tabs>
  );
}
