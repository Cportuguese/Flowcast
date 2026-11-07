import { View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { LogoMark } from './Logo';
import { Text } from './Text';

export function AuthHeader() {
  const insets = useSafeAreaInsets();

  return (
    <View className="flex-row items-center gap-3 px-6" style={{ paddingTop: insets.top + 24 }}>
      <LogoMark size={48} />
      <Text className="text-2xl font-semibold text-brand">Flowcast</Text>
    </View>
  );
}
