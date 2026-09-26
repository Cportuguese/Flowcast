import { router } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { OfflineNotice } from '../../components/OfflineNotice';
import { ScreenHeader } from '../../components/ScreenHeader';
import { api } from '../../lib/api';
import { fetchWithCache, isNetworkError } from '../../lib/cache';

interface VisitSummary {
  id: number;
  queue_entry_id: number;
  date: string;
  complaint: string;
  diagnosis: string;
}

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
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

  useEffect(() => {
    load().finally(() => setIsLoading(false));
  }, [load]);

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
        <ScreenHeader title="Visits" subtitle="Your past clinic visit records" />

        {offline && <OfflineNotice since={offline.since} />}

        {visits.length === 0 && !(offline && offline.since === null) && (
          <Text className="mt-8 text-sm text-slate-400">No visits recorded yet.</Text>
        )}

        {visits.map((visit, index) => (
          <Pressable
            key={visit.id}
            onPress={() => router.push(`/visit-detail?queueEntryId=${visit.queue_entry_id}`)}
            className={`rounded-xl border border-slate-200 bg-white p-4 ${index === 0 ? 'mt-6' : 'mt-3'}`}
          >
            <Text className="text-sm font-semibold text-slate-900">{formatDate(visit.date)}</Text>
            <Text className="mt-2 text-sm text-slate-600">{visit.complaint}</Text>
            <Text className="mt-1 text-xs text-slate-400">{visit.diagnosis}</Text>
          </Pressable>
        ))}
      </View>
    </ScrollView>
  );
}
