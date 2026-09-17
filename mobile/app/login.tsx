import axios from 'axios';
import { router } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import { AuthHeader } from '../components/AuthHeader';
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
    <KeyboardAvoidingView
      className="flex-1 bg-white"
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView keyboardShouldPersistTaps="handled">
        <AuthHeader />

        <View className="px-6 pt-10">
          <Text className="text-center text-3xl font-bold text-slate-900">Welcome back</Text>
          <Text className="mt-2 text-center text-base text-slate-500">
            Please enter your details to sign in.
          </Text>

          {error && (
            <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-center text-sm text-red-700">
              {error}
            </Text>
          )}

          <Text className="mb-1 mt-6 text-sm font-medium text-slate-700">Email Address</Text>
          <TextInput
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            keyboardType="email-address"
            autoComplete="email"
            placeholder="you@example.com"
            placeholderTextColor="#94a3b8"
            className="rounded-lg border border-slate-300 px-3 py-3 text-sm"
          />

          <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">Password</Text>
          <TextInput
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            autoComplete="current-password"
            placeholder="••••••••"
            placeholderTextColor="#94a3b8"
            className="rounded-lg border border-slate-300 px-3 py-3 text-sm"
          />

          <Pressable
            onPress={handleSubmit}
            disabled={isSubmitting}
            className="mt-6 flex-row items-center justify-center gap-2 rounded-lg bg-blue-600 py-3 disabled:opacity-50"
          >
            {isSubmitting ? (
              <ActivityIndicator color="white" />
            ) : (
              <>
                <Text className="font-semibold text-white">Sign in</Text>
                <Text className="font-semibold text-white">→</Text>
              </>
            )}
          </Pressable>

          <Pressable onPress={() => router.push('/register')} className="mb-8 mt-4">
            <Text className="text-center text-sm text-blue-600">
              Need an account? Register using your UDD email.
            </Text>
          </Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
