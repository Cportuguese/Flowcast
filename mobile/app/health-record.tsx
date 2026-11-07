import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  View,
} from 'react-native';
import { Text, TextInput } from '../components/Text';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import {
  HealthRecordForm,
  healthRecordFromUser,
  healthRecordPayload,
  localHealthRecordErrors,
  type HealthRecordValues,
} from '../components/HealthRecordForm';
import { useAuth, type User } from '../context/AuthContext';
import { api } from '../lib/api';
import { extractErrorMessage } from '../lib/errors';
import { fieldErrors } from '../lib/formErrors';

/**
 * The patient's own UDD Health Services record. Reached from the account
 * menu, and from booking when something is missing (`?from=booking`, which
 * returns there once saved).
 */
export default function HealthRecordScreen() {
  const insets = useSafeAreaInsets();
  const { user, updateUser } = useAuth();
  const { from } = useLocalSearchParams<{ from?: string }>();
  const [email, setEmail] = useState(user?.email ?? '');
  const [record, setRecord] = useState<HealthRecordValues>(() => healthRecordFromUser(user!));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [isSaving, setIsSaving] = useState(false);

  // The nurse may have checked (or corrected) the ID number at the counter
  // since the app last loaded the account, which locks the field here.
  useEffect(() => {
    api
      .get<User>('/user')
      .then((response) => updateUser(response.data))
      .catch(() => {});
  }, [updateUser]);

  async function handleSave() {
    setError(null);
    setSaved(false);
    const problems = localHealthRecordErrors(record);
    setErrors(problems);
    if (Object.keys(problems).length > 0) {
      setError('Please check the highlighted fields.');
      return;
    }

    setIsSaving(true);
    try {
      const response = await api.patch<User>('/user/profile', { email: email.trim(), ...healthRecordPayload(record) });
      updateUser(response.data);
      setSaved(true);
      if (from === 'booking') router.back();
    } catch (err) {
      const byField = fieldErrors(err);
      setErrors(byField);
      setError(Object.keys(byField).length > 0 ? 'Please check the highlighted fields.' : extractErrorMessage(err, 'Unable to save your record.'));
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <KeyboardAvoidingView className="flex-1 bg-white" behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}>
        <View className="px-6">
          <View className="flex-row items-center justify-between">
            <Text className="text-2xl font-bold text-slate-900">My health record</Text>
            <Pressable onPress={() => router.back()} hitSlop={8}>
              <Text className="text-sm text-slate-400">Close</Text>
            </Pressable>
          </View>
          <Text className="mt-1 text-sm text-slate-500">
            UDD Health Services uses this to identify you at the clinic and on your certificates.
          </Text>

          {error && <Text className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>}
          {saved && <Text className="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">Your record is saved.</Text>}

          <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">Email address</Text>
          <TextInput
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            keyboardType="email-address"
            autoComplete="email"
            className={`rounded-lg border px-3 py-3 text-sm ${errors.email ? 'border-red-400' : 'border-slate-300'}`}
          />
          {errors.email ? <Text className="mt-1 text-xs text-red-600">{errors.email}</Text> : null}
          <Text className="mt-1 text-xs text-slate-400">Your login, and where booking codes are sent.</Text>

          <HealthRecordForm values={record} onChange={setRecord} errors={errors} idLocked={Boolean(user?.school_id_verified_at)} />

          <Pressable
            onPress={handleSave}
            disabled={isSaving}
            className="mt-6 items-center justify-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
          >
            {isSaving ? <ActivityIndicator color="white" /> : <Text className="text-base font-semibold text-white">Save</Text>}
          </Pressable>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
