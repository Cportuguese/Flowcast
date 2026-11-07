import Ionicons from '@expo/vector-icons/Ionicons';
import { Pressable, View } from 'react-native';
import { Text, TextInput } from './Text';
import type { HealthRecord, PatientType } from '../context/AuthContext';
import { useDepartments } from '../lib/departments';
import { OptionPicker } from './OptionPicker';

/**
 * The UDD Health Services record as the patient fills it in — used by
 * sign-up and by "My health record". The API validates everything again;
 * the checks here only catch what can be fixed before sending.
 */
export interface HealthRecordValues {
  first_name: string;
  middle_name: string;
  no_middle_name: boolean;
  last_name: string;
  /** As typed, MM/DD/YYYY. */
  birthdate: string;
  sex: '' | 'male' | 'female';
  patient_type: '' | PatientType;
  course: string;
  department: string;
  school_id: string;
  contact_no: string;
  home_address: string;
}

export function emptyHealthRecord(): HealthRecordValues {
  return {
    first_name: '',
    middle_name: '',
    no_middle_name: false,
    last_name: '',
    birthdate: '',
    sex: '',
    patient_type: 'student',
    course: '',
    department: '',
    school_id: '',
    contact_no: '',
    home_address: '',
  };
}

export function healthRecordFromUser(user: HealthRecord): HealthRecordValues {
  const [year, month, day] = (user.birthdate ?? '').split('-');
  return {
    first_name: user.first_name ?? '',
    middle_name: user.middle_name ?? '',
    // Someone who completed the record without a middle name said so.
    no_middle_name: Boolean(user.first_name && user.last_name && !user.middle_name),
    last_name: user.last_name ?? '',
    birthdate: year ? `${month}/${day}/${year}` : '',
    sex: user.sex ?? '',
    patient_type: user.patient_type ?? 'student',
    course: user.course ?? '',
    department: user.department ?? '',
    school_id: user.school_id ?? '',
    contact_no: user.contact_no ?? '',
    home_address: user.home_address ?? '',
  };
}

/** What the API expects (birthdate as YYYY-MM-DD; course or department by type). */
export function healthRecordPayload(values: HealthRecordValues): Record<string, unknown> {
  const match = values.birthdate.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  const isStudent = values.patient_type === 'student';
  return {
    first_name: values.first_name.trim(),
    middle_name: values.no_middle_name ? null : values.middle_name.trim(),
    no_middle_name: values.no_middle_name,
    last_name: values.last_name.trim(),
    birthdate: match ? `${match[3]}-${match[1]}-${match[2]}` : values.birthdate,
    sex: values.sex || null,
    patient_type: values.patient_type || null,
    course: isStudent ? values.course || null : null,
    department: isStudent ? null : values.department || null,
    school_id: values.school_id.trim(),
    contact_no: values.contact_no.trim() || null,
    home_address: values.home_address.trim(),
  };
}

/** Problems worth catching before sending; the API has the final say. */
export function localHealthRecordErrors(values: HealthRecordValues): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!values.first_name.trim()) errors.first_name = 'Enter your first name.';
  if (!values.last_name.trim()) errors.last_name = 'Enter your last name.';
  if (!values.no_middle_name && !values.middle_name.trim()) errors.middle_name = 'Enter your middle name, or tick "I have no middle name".';
  if (!/^\d{2}\/\d{2}\/\d{4}$/.test(values.birthdate)) errors.birthdate = 'Enter your date of birth as MM/DD/YYYY.';
  if (!values.sex) errors.sex = 'Choose your sex.';
  if (values.patient_type === 'student' && !values.course) errors.course = 'Choose your course.';
  if (values.patient_type !== 'student' && !values.department) errors.department = 'Choose your department.';
  if (!values.school_id.trim()) errors.school_id = 'Enter your ID number.';
  if (values.home_address.trim().length < 5) errors.home_address = 'Enter your home address.';
  return errors;
}

/** "0304" -> "03/04", "03042004" -> "03/04/2004". */
function maskBirthdate(text: string) {
  const digits = text.replace(/\D/g, '').slice(0, 8);
  return [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4, 8)].filter(Boolean).join('/');
}

/** UDD student IDs: "231057651" -> "23-1057-651". */
function maskStudentId(text: string) {
  const digits = text.replace(/\D/g, '').slice(0, 9);
  return [digits.slice(0, 2), digits.slice(2, 6), digits.slice(6, 9)].filter(Boolean).join('-');
}

const PATIENT_TYPES: Array<{ value: PatientType; label: string }> = [
  { value: 'student', label: 'Student' },
  { value: 'faculty', label: 'Faculty' },
  { value: 'staff', label: 'Employee' },
];

const inputClass = (hasError: boolean) =>
  `rounded-lg border px-3 py-3 text-sm text-slate-900 ${hasError ? 'border-red-400' : 'border-slate-300'}`;

function FieldError({ message }: { message?: string }) {
  return message ? <Text className="mt-1 text-xs text-red-600">{message}</Text> : null;
}

function Label({ children }: { children: string }) {
  return <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">{children}</Text>;
}

function Chips<T extends string>({
  options,
  value,
  onChange,
}: {
  options: Array<{ value: T; label: string }>;
  value: T | '';
  onChange: (value: T) => void;
}) {
  return (
    <View className="flex-row gap-2">
      {options.map((option) => {
        const selected = value === option.value;
        return (
          <Pressable
            key={option.value}
            onPress={() => onChange(option.value)}
            accessibilityRole="radio"
            accessibilityState={{ selected }}
            className={`flex-1 items-center rounded-lg border py-3 ${selected ? 'border-brand bg-brand' : 'border-slate-300 bg-white'}`}
          >
            <Text className={`text-sm font-medium ${selected ? 'text-white' : 'text-slate-700'}`}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export function HealthRecordForm({
  values,
  onChange,
  errors,
  idLocked = false,
}: {
  values: HealthRecordValues;
  onChange: (values: HealthRecordValues) => void;
  errors: Record<string, string>;
  /** The nurse has checked the ID number against the UDD ID card, so only she can change it. */
  idLocked?: boolean;
}) {
  const departments = useDepartments();
  const set = (patch: Partial<HealthRecordValues>) => onChange({ ...values, ...patch });
  const isStudent = values.patient_type === 'student';

  const courseSections = departments
    .filter((department) => department.courses.length > 0)
    .map((department) => ({
      title: `${department.code} — ${department.name}`,
      options: department.courses.map((course) => ({ value: course.code, label: `${course.code} — ${course.name}`, hint: department.name })),
    }));
  const departmentSections = [
    {
      title: 'Departments',
      options: departments.map((department) => ({ value: department.code, label: `${department.code} — ${department.name}` })),
    },
  ];

  return (
    <View>
      <Label>First name</Label>
      <TextInput
        value={values.first_name}
        onChangeText={(first_name) => set({ first_name })}
        autoComplete="name-given"
        placeholder="e.g. Juan Miguel"
        placeholderTextColor="#94a3b8"
        className={inputClass(Boolean(errors.first_name))}
      />
      <FieldError message={errors.first_name} />

      <Label>Middle name</Label>
      <TextInput
        value={values.no_middle_name ? '' : values.middle_name}
        onChangeText={(middle_name) => set({ middle_name })}
        editable={!values.no_middle_name}
        autoComplete="name-middle"
        placeholder={values.no_middle_name ? 'No middle name' : 'Full middle name, not an initial'}
        placeholderTextColor="#94a3b8"
        className={`${inputClass(Boolean(errors.middle_name))} ${values.no_middle_name ? 'bg-slate-50' : ''}`}
      />
      <Pressable
        onPress={() => set({ no_middle_name: !values.no_middle_name, middle_name: '' })}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: values.no_middle_name }}
        className="mt-2 flex-row items-center gap-2"
      >
        <Ionicons name={values.no_middle_name ? 'checkbox' : 'square-outline'} size={20} color={values.no_middle_name ? '#0b1636' : '#94a3b8'} />
        <Text className="text-sm text-slate-600">I have no middle name</Text>
      </Pressable>
      <FieldError message={errors.middle_name} />

      <Label>Last name</Label>
      <TextInput
        value={values.last_name}
        onChangeText={(last_name) => set({ last_name })}
        autoComplete="name-family"
        placeholder="e.g. Dela Cruz"
        placeholderTextColor="#94a3b8"
        className={inputClass(Boolean(errors.last_name))}
      />
      <FieldError message={errors.last_name} />

      <Label>Date of birth</Label>
      <TextInput
        value={values.birthdate}
        onChangeText={(text) => set({ birthdate: maskBirthdate(text) })}
        keyboardType="number-pad"
        placeholder="MM/DD/YYYY"
        placeholderTextColor="#94a3b8"
        maxLength={10}
        className={inputClass(Boolean(errors.birthdate))}
      />
      <FieldError message={errors.birthdate} />

      <Label>Sex</Label>
      <Chips
        options={[
          { value: 'male', label: 'Male' },
          { value: 'female', label: 'Female' },
        ]}
        value={values.sex}
        onChange={(sex) => set({ sex })}
      />
      <FieldError message={errors.sex} />

      <Label>I am a</Label>
      <Chips options={PATIENT_TYPES} value={values.patient_type} onChange={(patient_type) => set({ patient_type })} />
      <FieldError message={errors.patient_type} />

      {isStudent ? (
        <OptionPicker
          label="Course"
          placeholder="Choose your course"
          value={values.course}
          sections={courseSections}
          onChange={(course) => set({ course })}
          error={errors.course}
        />
      ) : (
        <OptionPicker
          label="Department"
          placeholder="Choose your department or office"
          value={values.department}
          sections={departmentSections}
          onChange={(department) => set({ department })}
          error={errors.department}
        />
      )}

      <Label>ID number</Label>
      <TextInput
        value={values.school_id}
        onChangeText={(text) => set({ school_id: isStudent ? maskStudentId(text) : text.toUpperCase() })}
        editable={!idLocked}
        keyboardType={isStudent ? 'number-pad' : 'default'}
        autoCapitalize="characters"
        placeholder={isStudent ? 'e.g. 23-1057-651' : 'Your employee ID'}
        placeholderTextColor="#94a3b8"
        maxLength={20}
        className={`${inputClass(Boolean(errors.school_id))} ${idLocked ? 'bg-slate-50 text-slate-500' : ''}`}
      />
      {idLocked ? (
        <Text className="mt-1 text-xs text-slate-500">Checked against your UDD ID card by the clinic. Ask the nurse if it needs correcting.</Text>
      ) : (
        <Text className="mt-1 text-xs text-slate-400">The nurse checks this against your UDD ID card on your first visit.</Text>
      )}
      <FieldError message={errors.school_id} />

      <Label>Home address</Label>
      <TextInput
        value={values.home_address}
        onChangeText={(home_address) => set({ home_address })}
        autoComplete="street-address"
        placeholder="House no., street, barangay, town or city"
        placeholderTextColor="#94a3b8"
        multiline
        className={inputClass(Boolean(errors.home_address))}
        style={{ minHeight: 64, textAlignVertical: 'top' }}
      />
      <FieldError message={errors.home_address} />

      <Label>Contact number (optional)</Label>
      <TextInput
        value={values.contact_no}
        onChangeText={(contact_no) => set({ contact_no })}
        keyboardType="phone-pad"
        autoComplete="tel"
        placeholder="For clinic follow-ups"
        placeholderTextColor="#94a3b8"
        className={inputClass(Boolean(errors.contact_no))}
      />
      <FieldError message={errors.contact_no} />
    </View>
  );
}
