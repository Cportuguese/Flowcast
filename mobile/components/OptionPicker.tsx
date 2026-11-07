import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { Modal, Pressable, ScrollView, View } from 'react-native';
import { Text, TextInput } from './Text';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export interface PickerOption {
  value: string;
  label: string;
  hint?: string;
}

export interface PickerSection {
  title: string;
  options: PickerOption[];
}

interface Props {
  label: string;
  placeholder: string;
  value: string;
  sections: PickerSection[];
  onChange: (value: string) => void;
  error?: string;
}

/**
 * A field that opens a full-screen, searchable list grouped into sections —
 * React Native has no built-in dropdown, and UDD's course list is too long
 * for a row of chips.
 */
export function OptionPicker({ label, placeholder, value, sections, onChange, error }: Props) {
  const insets = useSafeAreaInsets();
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState('');

  const selected = sections.flatMap((section) => section.options).find((option) => option.value === value);
  const needle = query.trim().toLowerCase();
  const visible = sections
    .map((section) => ({
      ...section,
      options: section.options.filter(
        (option) => !needle || `${option.value} ${option.label} ${option.hint ?? ''} ${section.title}`.toLowerCase().includes(needle),
      ),
    }))
    .filter((section) => section.options.length > 0);

  function choose(option: PickerOption) {
    onChange(option.value);
    setIsOpen(false);
    setQuery('');
  }

  return (
    <>
      <Text className="mb-1 mt-4 text-sm font-medium text-slate-700">{label}</Text>
      <Pressable
        onPress={() => setIsOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={`${label}: ${selected?.label ?? placeholder}`}
        className={`flex-row items-center justify-between rounded-lg border px-3 py-3 ${error ? 'border-red-400' : 'border-slate-300'}`}
      >
        <Text className={`flex-1 text-sm ${selected ? 'text-slate-900' : 'text-slate-400'}`} numberOfLines={1}>
          {selected ? selected.label : placeholder}
        </Text>
        <Ionicons name="chevron-down" size={18} color="#94a3b8" />
      </Pressable>
      {error ? <Text className="mt-1 text-xs text-red-600">{error}</Text> : null}

      <Modal visible={isOpen} animationType="slide" onRequestClose={() => setIsOpen(false)}>
        <View className="flex-1 bg-white" style={{ paddingTop: insets.top + 12 }}>
          <View className="flex-row items-center justify-between px-6">
            <Text className="text-xl font-bold text-slate-900">{label}</Text>
            <Pressable onPress={() => setIsOpen(false)} hitSlop={8}>
              <Text className="text-sm text-slate-400">Close</Text>
            </Pressable>
          </View>
          <TextInput
            value={query}
            onChangeText={setQuery}
            placeholder="Search"
            placeholderTextColor="#94a3b8"
            autoCorrect={false}
            className="mx-6 mt-4 rounded-lg border border-slate-300 px-3 py-2.5 text-sm"
          />
          <ScrollView className="mt-2" contentContainerStyle={{ paddingBottom: insets.bottom + 24 }} keyboardShouldPersistTaps="handled">
            {visible.map((section) => (
              <View key={section.title} className="mt-3">
                <Text className="bg-slate-50 px-6 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {section.title}
                </Text>
                {section.options.map((option) => {
                  const isSelected = option.value === value;
                  return (
                    <Pressable
                      key={option.value}
                      onPress={() => choose(option)}
                      className={`flex-row items-center justify-between px-6 py-3 ${isSelected ? 'bg-blue-50' : ''}`}
                    >
                      <View className="flex-1 pr-3">
                        <Text className="text-sm font-medium text-slate-900">{option.label}</Text>
                        {option.hint ? <Text className="text-xs text-slate-500">{option.hint}</Text> : null}
                      </View>
                      {isSelected && <Ionicons name="checkmark" size={18} color="#2563eb" />}
                    </Pressable>
                  );
                })}
              </View>
            ))}
            {visible.length === 0 && <Text className="px-6 py-6 text-sm text-slate-400">Nothing matches "{query}".</Text>}
          </ScrollView>
        </View>
      </Modal>
    </>
  );
}
