import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { api, clearToken, getToken, onUnauthorized, setToken } from '../lib/api';
import { clearCache, isNetworkError, readCache, writeCache } from '../lib/cache';
import { disconnectEcho } from '../lib/echo';

export type PatientType = 'student' | 'faculty' | 'staff';

interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'personnel' | 'patient';
  [key: string]: unknown;
}

interface AuthContextValue {
  user: User | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (params: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    patient_type?: PatientType;
    contact_no?: string;
  }) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // A 401 anywhere means the token is dead (expired or revoked): drop the
  // session locally so the root layout's guard sends the user to /login.
  useEffect(() => {
    onUnauthorized(() => {
      disconnectEcho();
      void clearCache();
      setUser(null);
    });
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const token = await getToken();
        if (!token) {
          return;
        }

        const response = await api.get<User>('/user');
        setUser(response.data);
        void writeCache('user', response.data);
      } catch (err) {
        // No signal: the token may well be fine, so open with the last-known
        // profile and let the screens show their cached copies. Any real
        // rejection (401 etc.) still signs the device out.
        const cached = isNetworkError(err) ? await readCache<User>('user') : null;
        if (cached) {
          setUser(cached.data);
        } else {
          await clearToken().catch(() => {});
        }
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  async function login(email: string, password: string) {
    const response = await api.post<{ user: User; token: string }>('/login', {
      email,
      password,
      device: 'mobile',
    });
    // The Echo singleton bakes the bearer token in at construction, so tear
    // down any instance built under a previous (or absent) token.
    disconnectEcho();
    await setToken(response.data.token);
    setUser(response.data.user);
    void writeCache('user', response.data.user);
  }

  async function register(params: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    patient_type?: PatientType;
    contact_no?: string;
  }) {
    const response = await api.post<{ user: User; token: string }>('/register', {
      ...params,
      device: 'mobile',
    });
    disconnectEcho();
    await setToken(response.data.token);
    setUser(response.data.user);
    void writeCache('user', response.data.user);
  }

  async function logout() {
    try {
      await api.post('/logout');
    } finally {
      disconnectEcho();
      await clearToken();
      await clearCache();
      setUser(null);
    }
  }

  return (
    <AuthContext.Provider value={{ user, isLoading, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
