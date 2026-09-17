import { Image, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export function AuthHeader() {
  const insets = useSafeAreaInsets();

  return (
    <View className="px-6" style={{ paddingTop: insets.top + 24 }}>
      <Image
        source={require('../assets/logo.png')}
        resizeMode="contain"
        style={{ width: 96, height: 96 }}
      />
    </View>
  );
}
