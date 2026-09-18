import { Redirect, router } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { useAuth } from '../context/AuthContext';

export default function Index() {
  const { user, isLoading, logout } = useAuth();

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  if (!user) {
    return <Redirect href="/login" />;
  }

  if (user.role === 'patient') {
    return <Redirect href="/queue" />;
  }

  async function handleLogout() {
    await logout();
    router.replace('/login');
  }

  return (
    <View className="flex-1 items-center justify-center gap-4 bg-white">
      <Text className="text-lg">
        Welcome, {user.name} ({user.role})
      </Text>
      <Pressable onPress={handleLogout} className="rounded bg-gray-200 px-4 py-2">
        <Text className="font-medium text-gray-800">Log out</Text>
      </Pressable>
      <StatusBar style="auto" />
    </View>
  );
}
