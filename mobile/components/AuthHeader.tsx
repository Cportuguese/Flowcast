import { Image, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export function AuthHeader() {
  const insets = useSafeAreaInsets();

  return (
    <View className="items-center bg-[#0b1636] pb-8" style={{ paddingTop: insets.top + 32 }}>
      <View className="rounded-2xl bg-white px-4 py-3">
        <Image
          source={require('../assets/logo.png')}
          resizeMode="contain"
          style={{ width: 96, height: 96 }}
        />
      </View>
    </View>
  );
}
