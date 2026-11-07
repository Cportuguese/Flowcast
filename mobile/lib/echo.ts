import Echo from 'laravel-echo';
import PusherModule from 'pusher-js/react-native';
import { apiBaseUrl, getToken, realtimeHost } from './api';

// pusher-js's react-native type declarations describe a default export,
// but its actual bundled runtime for React Native only exposes a named
// `Pusher` property (no default) — this bridges that mismatch.
const Pusher = (PusherModule as unknown as { Pusher: typeof PusherModule }).Pusher;

const authEndpoint = `${apiBaseUrl.replace(/\/api\/?$/, '')}/broadcasting/auth`;

let echo: Echo<'reverb'> | null = null;

export async function getEcho() {
  if (echo) return echo;

  const token = await getToken();

  echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: process.env.EXPO_PUBLIC_REVERB_APP_KEY,
    wsHost: realtimeHost,
    // pusher-js dials wsPort for ws:// but wssPort for wss://, which
    // defaults to 443 — so without both, TLS on any other port is ignored.
    wsPort: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 8080),
    wssPort: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 443),
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
