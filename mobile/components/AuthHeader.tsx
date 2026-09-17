import { Text, View } from 'react-native';

export function AuthHeader() {
  return (
    <View className="bg-[#0b1636] px-6 pb-10 pt-20">
      <View className="h-12 w-12 items-center justify-center rounded-2xl bg-blue-600">
        <View className="h-5 w-5 rotate-45 rounded-sm border-2 border-white" />
      </View>
      <Text className="mt-4 text-xs font-semibold tracking-[3px] text-blue-300">
        CAMPUS HEALTH PORTAL
      </Text>
      <Text className="mt-1 text-3xl font-extrabold text-white">
        FLOW<Text className="text-blue-400">CAST</Text>
      </Text>
    </View>
  );
}
