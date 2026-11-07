import { router } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  View,
} from 'react-native';
import { Text, TextInput } from '../components/Text';
import { AuthHeader } from '../components/AuthHeader';
import { useAuth } from '../context/AuthContext';
import { extractErrorMessage } from '../lib/errors';

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
      setError(extractErrorMessage(err, 'Unable to log in.'));
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

        <View className="px-6 pt-8">
          <Text className="text-3xl font-bold text-slate-900">Welcome Back</Text>
          <Text className="mt-1 text-base text-slate-500">Sign in to your clinic account</Text>

          {error && (
            <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
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
            placeholder="Enter your email"
            placeholderTextColor="#94a3b8"
            className="rounded-lg border border-slate-300 px-3 py-3 text-sm"
          />

          <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">Password</Text>
          <TextInput
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            autoComplete="current-password"
            placeholder="Enter your password"
            placeholderTextColor="#94a3b8"
            className="rounded-lg border border-slate-300 px-3 py-3 text-sm"
          />

          <Pressable
            onPress={handleSubmit}
            disabled={isSubmitting}
            className="mt-6 items-center justify-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
          >
            {isSubmitting ? (
              <ActivityIndicator color="white" />
            ) : (
              <Text className="text-base font-semibold text-white">Sign in</Text>
            )}
          </Pressable>

          <Pressable onPress={() => router.push('/register')} className="mb-8 mt-5">
            <Text className="text-center text-sm text-slate-500">
              Don't have an account? <Text className="font-semibold text-blue-600">Create Account</Text>
            </Text>
          </Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
