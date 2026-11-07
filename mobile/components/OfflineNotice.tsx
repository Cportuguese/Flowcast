import Ionicons from '@expo/vector-icons/Ionicons';
import { View } from 'react-native';
import { Text } from './Text';
import { formatSavedAt } from '../lib/cache';

// Shown above cached content so a stale queue number is never mistaken
// for a live one. `since` is when the cached copy was fetched; null when
// the request failed and there was nothing cached to fall back to.
export function OfflineNotice({ since }: { since: string | null }) {
  return (
    <View className="mt-4 flex-row items-center gap-2 rounded-lg bg-amber-50 px-3 py-2">
      <Ionicons name="cloud-offline-outline" size={16} color="#b45309" />
      <Text className="flex-1 text-xs text-amber-800">
        {since
          ? `Can't reach the clinic — showing what we had at ${formatSavedAt(since)}. Pull down to retry.`
          : "Can't reach the clinic. Pull down to retry."}
      </Text>
    </View>
  );
}
