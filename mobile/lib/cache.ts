import AsyncStorage from '@react-native-async-storage/async-storage';
import axios from 'axios';

// Last-known copies of the few screens a patient may open with no signal
// (the clinic waiting room is a wifi dead spot). Nothing here is a source
// of truth — it's what the screen shows while it can't reach the API.

interface Cached<T> {
  savedAt: string;
  data: T;
}

const PREFIX = 'flowcast_cache:';

export async function readCache<T>(key: string): Promise<Cached<T> | null> {
  try {
    const raw = await AsyncStorage.getItem(PREFIX + key);
    return raw ? (JSON.parse(raw) as Cached<T>) : null;
  } catch {
    return null;
  }
}

export async function writeCache<T>(key: string, data: T) {
  try {
    await AsyncStorage.setItem(PREFIX + key, JSON.stringify({ savedAt: new Date().toISOString(), data }));
  } catch {
    // Best effort; the live data is already on screen.
  }
}

// Called on logout so the next account on this phone doesn't see the
// previous patient's queue number or visit list.
export async function clearCache() {
  try {
    const keys = await AsyncStorage.getAllKeys();
    await AsyncStorage.multiRemove(keys.filter((k) => k.startsWith(PREFIX)));
  } catch {
    // ignore
  }
}

/** True when the request never reached the server (no signal, DNS, timeout). */
export function isNetworkError(err: unknown) {
  return axios.isAxiosError(err) && !err.response;
}

/**
 * Fetch, caching the result; on a network failure fall back to the last
 * cached copy. Any other error (401, 500…) still throws so the caller
 * handles it the way it already does.
 */
export async function fetchWithCache<T>(key: string, fetcher: () => Promise<T>): Promise<{ data: T; offlineSince: string | null }> {
  try {
    const data = await fetcher();
    void writeCache(key, data);
    return { data, offlineSince: null };
  } catch (err) {
    if (!isNetworkError(err)) throw err;
    const cached = await readCache<T>(key);
    if (!cached) throw err;
    return { data: cached.data, offlineSince: cached.savedAt };
  }
}

export function formatSavedAt(iso: string) {
  const d = new Date(iso);
  const sameDay = d.toDateString() === new Date().toDateString();
  return sameDay
    ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleDateString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
