import type { ReactNode } from 'react';
import { View } from 'react-native';
import { Text } from './Text';

/** Title row used at the top of every signed-in screen. */
export function ScreenHeader({
  title,
  subtitle,
  right,
}: {
  title: string;
  subtitle?: string;
  right?: ReactNode;
}) {
  return (
    <View className="flex-row items-start justify-between">
      <View className="flex-1">
        <Text className="text-2xl font-bold text-slate-900">{title}</Text>
        {subtitle && <Text className="mt-1 text-base text-slate-500">{subtitle}</Text>}
      </View>
      {right}
    </View>
  );
}
