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
import {
  HealthRecordForm,
  emptyHealthRecord,
  healthRecordPayload,
  localHealthRecordErrors,
  type HealthRecordValues,
} from '../components/HealthRecordForm';
import { useAuth } from '../context/AuthContext';
import { extractErrorMessage } from '../lib/errors';
import { fieldErrors } from '../lib/formErrors';

const ACCOUNT_FIELDS = ['email', 'password', 'password_confirmation'];

/**
 * Sign-up in two steps: the account, then the UDD Health Services record
 * the clinic keeps on every patient (the same record booking asks them to
 * confirm).
 */
export default function Register() {
  const { register } = useAuth();
  const [step, setStep] = useState<1 | 2>(1);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [record, setRecord] = useState<HealthRecordValues>(emptyHealthRecord);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  function handleNext() {
    const problems: Record<string, string> = {};
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) problems.email = 'Enter a valid email address — booking codes are sent there.';
    if (password.length < 8) problems.password = 'Use at least 8 characters.';
    if (password !== passwordConfirmation) problems.password_confirmation = 'The passwords do not match.';
    setErrors(problems);
    if (Object.keys(problems).length === 0) setStep(2);
  }

  async function handleSubmit() {
    setError(null);
    const problems = localHealthRecordErrors(record);
    setErrors(problems);
    if (Object.keys(problems).length > 0) {
      setError('Please check the highlighted fields.');
      return;
    }

    setIsSubmitting(true);
    try {
      await register({
        email: email.trim(),
        password,
        password_confirmation: passwordConfirmation,
        ...healthRecordPayload(record),
      });
      router.replace('/');
    } catch (err) {
      const byField = fieldErrors(err);
      setErrors(byField);
      // A problem with the account itself is fixed on step 1.
      if (ACCOUNT_FIELDS.some((field) => byField[field])) {
        setStep(1);
      }
      setError(Object.keys(byField).length > 0 ? 'Please check the highlighted fields.' : extractErrorMessage(err, 'Unable to register.'));
    } finally {
      setIsSubmitting(false);
    }
  }

  const inputClass = (field: string) =>
    `rounded-lg border px-3 py-3 text-sm ${errors[field] ? 'border-red-400' : 'border-slate-300'}`;

  return (
    <KeyboardAvoidingView className="flex-1 bg-white" behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView keyboardShouldPersistTaps="handled">
        <AuthHeader />

        <View className="px-6 pt-8">
          <Text className="text-xs font-semibold uppercase tracking-wide text-slate-400">Step {step} of 2</Text>
          <Text className="mt-1 text-3xl font-bold text-slate-900">{step === 1 ? 'Create Account' : 'Your health record'}</Text>
          <Text className="mt-1 text-base text-slate-500">
            {step === 1
              ? 'Register to book the clinic and track your visits'
              : 'UDD Health Services keeps this record for every patient'}
          </Text>

          {error && <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>}

          {step === 1 ? (
            <>
              <Text className="mb-1 mt-6 text-sm font-medium text-slate-700">Email address</Text>
              <TextInput
                value={email}
                onChangeText={setEmail}
                autoCapitalize="none"
                keyboardType="email-address"
                autoComplete="email"
                placeholder="Enter your email"
                placeholderTextColor="#94a3b8"
                className={inputClass('email')}
              />
              {errors.email ? <Text className="mt-1 text-xs text-red-600">{errors.email}</Text> : null}

              <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">Password</Text>
              <TextInput
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                autoComplete="new-password"
                placeholder="At least 8 characters"
                placeholderTextColor="#94a3b8"
                className={inputClass('password')}
              />
              {errors.password ? <Text className="mt-1 text-xs text-red-600">{errors.password}</Text> : null}

              <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">Confirm password</Text>
              <TextInput
                value={passwordConfirmation}
                onChangeText={setPasswordConfirmation}
                secureTextEntry
                autoComplete="new-password"
                placeholder="Re-enter your password"
                placeholderTextColor="#94a3b8"
                className={inputClass('password_confirmation')}
              />
              {errors.password_confirmation ? (
                <Text className="mt-1 text-xs text-red-600">{errors.password_confirmation}</Text>
              ) : null}

              <Pressable onPress={handleNext} className="mt-6 items-center justify-center rounded-lg bg-brand py-3.5">
                <Text className="text-base font-semibold text-white">Next</Text>
              </Pressable>

              <Pressable onPress={() => router.push('/login')} className="mb-8 mt-5">
                <Text className="text-center text-sm text-slate-500">
                  Already have an account? <Text className="font-semibold text-blue-600">Sign in</Text>
                </Text>
              </Pressable>
            </>
          ) : (
            <>
              <HealthRecordForm values={record} onChange={setRecord} errors={errors} />

              <Pressable
                onPress={handleSubmit}
                disabled={isSubmitting}
                className="mt-6 items-center justify-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
              >
                {isSubmitting ? (
                  <ActivityIndicator color="white" />
                ) : (
                  <Text className="text-base font-semibold text-white">Create account</Text>
                )}
              </Pressable>

              <Pressable onPress={() => setStep(1)} className="mb-8 mt-5">
                <Text className="text-center text-sm text-slate-500">Back</Text>
              </Pressable>
            </>
          )}
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
