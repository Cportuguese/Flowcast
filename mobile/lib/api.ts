import axios from 'axios';
import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'flowcast_token';

export const api = axios.create({
  baseURL: process.env.EXPO_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api',
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

export function getToken() {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export function setToken(token: string) {
  return SecureStore.setItemAsync(TOKEN_KEY, token);
}

export function clearToken() {
  return SecureStore.deleteItemAsync(TOKEN_KEY);
}
