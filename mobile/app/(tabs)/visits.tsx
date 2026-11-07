import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { Text } from '../../components/Text';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { OfflineNotice } from '../../components/OfflineNotice';
import { ScreenHeader } from '../../components/ScreenHeader';
import { api } from '../../lib/api';
import { fetchWithCache, isNetworkError } from '../../lib/cache';
import { formatLongDate } from '../../lib/time';

interface VisitSummary {
  id: number;
  date: string;
  complaint: string;
  intervention: string;
  seen_by: string;
  seen_by_title: string | null;
}

export default function VisitHistory() {
  const insets = useSafeAreaInsets();
  const [visits, setVisits] = useState<VisitSummary[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [offline, setOffline] = useState<{ since: string | null } | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, offlineSince } = await fetchWithCache('visits-mine', async () => {
        const response = await api.get<VisitSummary[]>('/visits/mine');
        return response.data;
      });
      setVisits(data);
      setOffline(offlineSince ? { since: offlineSince } : null);
    } catch (err) {
      if (isNetworkError(err)) setOffline({ since: null });
    }
  }, []);

  // On every visit to the tab, not just the first: tabs stay mounted, so a
  // visit recorded while the patient was elsewhere in the app would not
  // show until they pulled to refresh.
  useFocusEffect(
    useCallback(() => {
      load().finally(() => setIsLoading(false));
    }, [load]),
  );

  async function handleRefresh() {
    setIsRefreshing(true);
    await load();
    setIsRefreshing(false);
  }

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  return (
    <ScrollView
      className="flex-1 bg-white"
      contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={isRefreshing} onRefresh={handleRefresh} />}
    >
      <View className="px-6">
        <ScreenHeader title="Visits" subtitle="Your UDD Health Services records" />

        {offline && <OfflineNotice since={offline.since} />}

        {visits.length === 0 && !(offline && offline.since === null) && (
          <Text className="mt-8 text-sm text-slate-400">No visits recorded yet.</Text>
        )}

        {visits.map((visit, index) => (
          <Pressable
            key={visit.id}
            onPress={() => router.push(`/visit-detail?visitId=${visit.id}`)}
            className={`rounded-xl border border-slate-200 bg-white p-4 ${index === 0 ? 'mt-6' : 'mt-3'}`}
          >
            <Text className="text-sm font-semibold text-slate-900">{formatLongDate(visit.date)}</Text>
            <Text className="mt-0.5 text-xs text-slate-500">
              {visit.seen_by}
              {visit.seen_by_title && visit.seen_by_title !== 'Head Nurse' ? ` · ${visit.seen_by_title}` : ''}
            </Text>
            <Text className="mt-2 text-sm text-slate-700">{visit.complaint}</Text>
            <Text className="mt-1 text-xs text-slate-500" numberOfLines={2}>
              {visit.intervention}
            </Text>
          </Pressable>
        ))}
      </View>
    </ScrollView>
  );
}
