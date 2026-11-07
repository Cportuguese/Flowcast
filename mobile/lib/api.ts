import axios from 'axios';
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'flowcast_token';

// The dev laptop's LAN IP changes whenever the Wi-Fi, hotspot or DHCP lease
// does, which silently strands a hardcoded host. Expo already knows the host
// that served this bundle, so in dev we reuse it and keep only the scheme,
// port and path from the env URL. `hostUri` is undefined in release builds,
// where EXPO_PUBLIC_API_URL is the real (and stable) answer.
//
// Only a local address is swapped, and only for another local address: an
// env URL naming a deployed server is used as written, and a tunnel host
// (`*.exp.direct`) serves the bundle, not the API.
const fallbackApiUrl = process.env.EXPO_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const reverbHost = process.env.EXPO_PUBLIC_REVERB_HOST;

function isLocalHost(host: string | undefined) {
  return !!host && /^(localhost$|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(host);
}

const bundleHost = Constants.expoConfig?.hostUri?.split(':')[0];
const devHost = isLocalHost(bundleHost) ? bundleHost : undefined;
const configuredApiHost = fallbackApiUrl.match(/\/\/([^/:]+)/)?.[1];

export const apiBaseUrl = devHost && isLocalHost(configuredApiHost)
  ? fallbackApiUrl.replace(/\/\/[^/:]+/, `//${devHost}`)
  : fallbackApiUrl;

export const realtimeHost = devHost && (!reverbHost || isLocalHost(reverbHost)) ? devHost : reverbHost;

export const api = axios.create({
  baseURL: apiBaseUrl,
  headers: {
    Accept: 'application/json',
  },
});

api.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Registered by AuthProvider so an expired/revoked token clears React state
// as well as SecureStore. Skips the auth routes themselves: a 401 there is
// either a bad attempt or an already-dead token, and the caller handles both.
let unauthorizedHandler: (() => void) | null = null;

export function onUnauthorized(handler: () => void) {
  unauthorizedHandler = handler;
}

api.interceptors.response.use(
  (response) => response,
  async (error: unknown) => {
    if (axios.isAxiosError(error) && error.response?.status === 401) {
      const url = error.config?.url ?? '';
      const isAuthRoute = /\/(login|logout|register)$/.test(url);

      if (!isAuthRoute && (await SecureStore.getItemAsync(TOKEN_KEY))) {
        await clearToken().catch(() => {});
        unauthorizedHandler?.();
      }
    }

    return Promise.reject(error);
  },
);

export function getToken() {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export function setToken(token: string) {
  return SecureStore.setItemAsync(TOKEN_KEY, token);
}

export function clearToken() {
  return SecureStore.deleteItemAsync(TOKEN_KEY);
}
