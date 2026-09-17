import axios from 'axios';
import { Link, router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, Text, TextInput, View } from 'react-native';
import { useAuth } from '../context/AuthContext';

export default function Register() {
  const { register } = useAuth();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit() {
    setError(null);
    setIsSubmitting(true);

    try {
      await register({
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      router.replace('/');
    } catch (err) {
      if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as { message?: string; errors?: Record<string, string[]> };
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        setError(firstError ?? data.message ?? 'Unable to register.');
      } else {
        setError('Unable to register.');
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <View className="flex-1 justify-center bg-white px-6">
      <Text className="mb-6 text-2xl font-semibold text-gray-900">Create account</Text>

      {error && (
        <Text className="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{error}</Text>
      )}

      <Text className="mb-1 text-sm font-medium text-gray-700">Name</Text>
      <TextInput
        value={name}
        onChangeText={setName}
        autoComplete="name"
        className="mb-4 rounded border border-gray-300 px-3 py-2"
      />

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
        autoComplete="new-password"
        className="mb-4 rounded border border-gray-300 px-3 py-2"
      />

      <Text className="mb-1 text-sm font-medium text-gray-700">Confirm password</Text>
      <TextInput
        value={passwordConfirmation}
        onChangeText={setPasswordConfirmation}
        secureTextEntry
        autoComplete="new-password"
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
          <Text className="font-medium text-white">Register</Text>
        )}
      </Pressable>

      <Link href="/login" className="mt-4 text-center text-indigo-600">
        Already have an account? Log in
      </Link>
    </View>
  );
}
