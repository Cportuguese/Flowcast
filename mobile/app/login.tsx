import axios from 'axios';
import { Link, router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, Text, TextInput, View } from 'react-native';
import { useAuth } from '../context/AuthContext';

export default function Login() {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit() {
    setError(null);
    setIsSubmitting(true);

    try {
      await login(email, password);
      router.replace('/');
    } catch (err) {
      if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as { message?: string };
        setError(data.message ?? 'Unable to log in.');
      } else {
        setError('Unable to log in.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <View className="flex-1 justify-center bg-white px-6">
      <Text className="mb-6 text-2xl font-semibold text-gray-900">Log in</Text>

      {error && (
        <Text className="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{error}</Text>
      )}

      <Text className="mb-1 text-sm font-medium text-gray-700">Email</Text>
      <TextInput
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        keyboardType="email-address"
        autoComplete="email"
        className="mb-4 rounded border border-gray-300 px-3 py-2"
      />

      <Text className="mb-1 text-sm font-medium text-gray-700">Password</Text>
      <TextInput
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="current-password"
        className="mb-6 rounded border border-gray-300 px-3 py-2"
      />

      <Pressable
        onPress={handleSubmit}
        disabled={isSubmitting}
        className="items-center rounded bg-indigo-600 py-3 disabled:opacity-50"
      >
        {isSubmitting ? (
          <ActivityIndicator color="white" />
        ) : (
          <Text className="font-medium text-white">Log in</Text>
        )}
      </Pressable>

      <Link href="/register" className="mt-4 text-center text-indigo-600">
        Need an account? Register
      </Link>
    </View>
  );
}
