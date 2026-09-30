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
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api } from '../lib/api';
import { extractErrorMessage } from '../lib/errors';

const inputClass = 'rounded-lg border border-slate-300 px-3 py-3 text-sm';

export default function ChangePassword() {
  const insets = useSafeAreaInsets();

  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isDone, setIsDone] = useState(false);

  async function handleSubmit() {
    setError(null);
    setIsSubmitting(true);

    try {
      await api.patch('/user/password', {
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      });
      setIsDone(true);
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to update the password.'));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <KeyboardAvoidingView className="flex-1 bg-white" behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}
        keyboardShouldPersistTaps="handled"
      >
        <View className="px-6">
          <View className="flex-row items-center justify-between">
            <Text className="text-2xl font-bold text-slate-900">Change password</Text>
            <Pressable onPress={() => router.back()} hitSlop={8}>
              <Text className="text-sm text-slate-400">{isDone ? 'Done' : 'Cancel'}</Text>
            </Pressable>
          </View>

          {isDone ? (
            <View className="mt-8 rounded-2xl border border-green-200 bg-green-50 p-6">
              <Text className="text-base font-semibold text-green-900">Password updated</Text>
              <Text className="mt-1 text-sm text-green-800">
                You stay signed in on this phone. Any other device was signed out.
              </Text>
            </View>
          ) : (
            <>
              {error && (
                <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>
              )}

              <View className="mt-6 gap-4">
                <View>
                  <Text className="mb-1.5 text-sm font-medium text-slate-700">Current password</Text>
                  <TextInput
                    value={currentPassword}
                    onChangeText={setCurrentPassword}
                    secureTextEntry
                    autoComplete="current-password"
                    placeholderTextColor="#94a3b8"
                    className={inputClass}
                  />
                </View>
                <View>
                  <Text className="mb-1.5 text-sm font-medium text-slate-700">New password</Text>
                  <TextInput
                    value={password}
                    onChangeText={setPassword}
                    secureTextEntry
                    autoComplete="new-password"
                    placeholder="At least 8 characters"
                    placeholderTextColor="#94a3b8"
                    className={inputClass}
                  />
                </View>
                <View>
                  <Text className="mb-1.5 text-sm font-medium text-slate-700">Confirm new password</Text>
                  <TextInput
                    value={confirmation}
                    onChangeText={setConfirmation}
                    secureTextEntry
                    autoComplete="new-password"
                    placeholderTextColor="#94a3b8"
                    className={inputClass}
                    onSubmitEditing={handleSubmit}
                  />
                </View>
              </View>

              <Pressable
                onPress={handleSubmit}
                disabled={isSubmitting || !currentPassword || !password || !confirmation}
                className="mt-8 items-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
              >
                {isSubmitting ? (
                  <ActivityIndicator color="white" />
                ) : (
                  <Text className="text-base font-semibold text-white">Update password</Text>
                )}
              </Pressable>
            </>
          )}
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
