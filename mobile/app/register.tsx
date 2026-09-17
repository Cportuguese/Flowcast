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
    <KeyboardAvoidingView
      className="flex-1 bg-white"
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView keyboardShouldPersistTaps="handled">
        <AuthHeader />

        <View className="px-6 pt-8">
          <Text className="text-3xl font-bold text-slate-900">Create Account</Text>
          <Text className="mt-1 text-base text-slate-500">
            Register to track your clinic visits
          </Text>

          {error && (
            <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
              {error}
            </Text>
          )}

          <Text className="mb-1 mt-6 text-sm font-medium text-slate-700">Name</Text>
          <TextInput
            value={name}
            onChangeText={setName}
            autoComplete="name"
            placeholder="Enter your full name"
            placeholderTextColor="#94a3b8"
            className="rounded-lg border border-slate-300 px-3 py-3 text-sm"
          />

          <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">Email Address</Text>
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
            autoComplete="new-password"
            placeholder="Enter your password"
            placeholderTextColor="#94a3b8"
            className="rounded-lg border border-slate-300 px-3 py-3 text-sm"
          />

          <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">Confirm Password</Text>
          <TextInput
            value={passwordConfirmation}
            onChangeText={setPasswordConfirmation}
            secureTextEntry
            autoComplete="new-password"
            placeholder="Re-enter your password"
            placeholderTextColor="#94a3b8"
            className="rounded-lg border border-slate-300 px-3 py-3 text-sm"
          />

          <Pressable
            onPress={handleSubmit}
            disabled={isSubmitting}
            className="mt-6 items-center justify-center rounded-lg bg-[#0b1636] py-3.5 disabled:opacity-50"
          >
            {isSubmitting ? (
              <ActivityIndicator color="white" />
            ) : (
              <Text className="text-base font-semibold text-white">Create Account</Text>
            )}
          </Pressable>

          <Pressable onPress={() => router.push('/login')} className="mb-8 mt-5">
            <Text className="text-center text-sm text-slate-500">
              Already have an account? <Text className="font-semibold text-blue-600">Sign in</Text>
            </Text>
          </Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
