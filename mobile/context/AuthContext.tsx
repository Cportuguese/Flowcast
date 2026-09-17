import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { api, clearToken, getToken, setToken } from '../lib/api';

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
  }) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        const token = await getToken();
        if (!token) {
          return;
        }

        const response = await api.get<User>('/user');
        setUser(response.data);
      } catch {
        await clearToken().catch(() => {});
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  async function login(email: string, password: string) {
    const response = await api.post<{ user: User; token: string }>('/login', {
      email,
      password,
    });
    await setToken(response.data.token);
    setUser(response.data.user);
  }

  async function register(params: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) {
    const response = await api.post<{ user: User; token: string }>('/register', params);
    await setToken(response.data.token);
    setUser(response.data.user);
  }

  async function logout() {
    try {
      await api.post('/logout');
    } finally {
      await clearToken();
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
