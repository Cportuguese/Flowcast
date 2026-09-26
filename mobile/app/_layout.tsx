import { Stack } from 'expo-router';
import { ActivityIndicator, View } from 'react-native';
import '../global.css';
import { AuthProvider, useAuth } from '../context/AuthContext';

export default function RootLayout() {
  return (
    <AuthProvider>
      <RootNavigator />
    </AuthProvider>
  );
}

/**
 * Single auth guard for every screen. Signed-out users can only reach
 * login/register (any other route, including a deep link, is redirected
 * there); signed-in users can't land back on the auth screens.
 */
function RootNavigator() {
  const { user, isLoading } = useAuth();

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  const isSignedIn = user !== null;

  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Protected guard={!isSignedIn}>
        <Stack.Screen name="login" />
        <Stack.Screen name="register" />
      </Stack.Protected>

      <Stack.Protected guard={isSignedIn}>
        <Stack.Screen name="index" />
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="book-appointment" />
        <Stack.Screen name="visit-detail" />
        <Stack.Screen name="change-password" />
      </Stack.Protected>
    </Stack>
  );
}
