import { Redirect, router } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, Pressable, View } from 'react-native';
import { LogoMark } from '../components/Logo';
import { Text } from '../components/Text';
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
    return <Redirect href="/(tabs)" />;
  }

  async function handleLogout() {
    await logout();
    router.replace('/login');
  }

  // Admin / personnel have no mobile UI; point them to the web dashboard.
  return (
    <View className="flex-1 items-center justify-center bg-white px-8">
      <LogoMark size={80} />
      <Text className="mt-6 text-center text-xl font-bold text-slate-900">
        Staff tools are on the web dashboard
      </Text>
      <Text className="mt-2 text-center text-base text-slate-500">
        The mobile app is for patients. Sign in on the Flowcast website to manage the queue,
        appointments, and records.
      </Text>
      <Text className="mt-6 text-sm text-slate-400">
        Signed in as {user.display_name} · {user.job_title ?? user.role}
      </Text>
      <Pressable
        onPress={handleLogout}
        className="mt-4 w-full items-center rounded-lg bg-brand py-3.5"
      >
        <Text className="text-base font-semibold text-white">Log out</Text>
      </Pressable>
      <StatusBar style="auto" />
    </View>
  );
}
