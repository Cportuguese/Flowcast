import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { api, clearToken, getToken, onUnauthorized, setToken } from '../lib/api';
import { clearCache, isNetworkError, readCache, writeCache } from '../lib/cache';
import { disconnectEcho } from '../lib/echo';

export type PatientType = 'student' | 'faculty' | 'staff';

/** The UDD Health Services record, as the API stores it on the user. */
export interface HealthRecord {
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  birthdate: string | null;
  sex: 'male' | 'female' | null;
  home_address: string | null;
  patient_type: PatientType | null;
  department: string | null;
  course: string | null;
  school_id: string | null;
  contact_no: string | null;
}

export interface User extends HealthRecord {
  id: number;
  name: string;
  email: string;
  /** Set once the nurse has checked the ID number against the patient's UDD ID card. */
  school_id_verified_at?: string | null;
  role: 'admin' | 'personnel' | 'patient';
  display_name: string;
  job_title: string | null;
  [key: string]: unknown;
}

/** Everything sign-up sends: the account plus the health record. */
export type RegisterParams = Record<string, unknown> & {
  email: string;
  password: string;
  password_confirmation: string;
};

interface AuthContextValue {
  user: User | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (params: RegisterParams) => Promise<void>;
  logout: () => Promise<void>;
  /** Replace the signed-in user after the health record was edited. */
  updateUser: (user: User) => void;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

/**
 * Whether the record has everything booking needs (mirrors the API's
 * User::hasCompleteHealthRecord, so the phone can ask before it is refused).
 */
export function hasCompleteHealthRecord(user: HealthRecord | null) {
  if (!user) return false;
  return Boolean(
    user.first_name &&
      user.last_name &&
      user.birthdate &&
      user.sex &&
      user.home_address &&
      user.department &&
      user.school_id &&
      (user.patient_type !== 'student' || user.course),
  );
}

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

  async function register(params: RegisterParams) {
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

  // Stable across renders: screens call it from effects (the health record
  // refreshes the account when it opens), and a new function every render
  // would re-run those effects in a loop.
  const updateUser = useCallback((next: User) => {
    setUser(next);
    void writeCache('user', next);
  }, []);

  return (
    <AuthContext.Provider value={{ user, isLoading, login, register, logout, updateUser }}>
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
