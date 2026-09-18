import Echo from 'laravel-echo';
import Pusher from 'pusher-js/react-native';
import { getToken } from './api';

const apiUrl = process.env.EXPO_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const authEndpoint = `${apiUrl.replace(/\/api\/?$/, '')}/broadcasting/auth`;

let echo: Echo<'reverb'> | null = null;

export async function getEcho() {
  if (echo) return echo;

  const token = await getToken();

  echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: process.env.EXPO_PUBLIC_REVERB_APP_KEY,
    wsHost: process.env.EXPO_PUBLIC_REVERB_HOST,
    wsPort: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 8080),
    forceTLS: process.env.EXPO_PUBLIC_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    },
  });

  return echo;
}

export function disconnectEcho() {
  echo?.disconnect();
  echo = null;
}
