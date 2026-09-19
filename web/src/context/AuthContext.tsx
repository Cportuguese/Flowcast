import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, clearToken, getToken, onUnauthorized, setToken } from '../lib/api'
import { disconnectEcho } from '../lib/echo'

interface User {
  id: number
  name: string
  email: string
  role: 'admin' | 'personnel' | 'patient'
  [key: string]: unknown
}

interface AuthContextValue {
  user: User | null
  isLoading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  // A 401 anywhere means the token is dead (expired or revoked): drop the
  // session locally so ProtectedRoute bounces to /login instead of leaving a
  // "signed in" UI whose every click fails.
  useEffect(() => {
    onUnauthorized(() => {
      disconnectEcho()
      setUser(null)
    })
  }, [])

  useEffect(() => {
    if (!getToken()) {
      setIsLoading(false)
      return
    }

    api
      .get<User>('/user')
      .then((response) => setUser(response.data))
      .catch(() => clearToken())
      .finally(() => setIsLoading(false))
  }, [])

  async function login(email: string, password: string) {
    const response = await api.post<{ user: User; token: string }>('/login', {
      email,
      password,
      device: 'web',
    })
    // The Echo singleton bakes the bearer token in at construction, so tear
    // down any instance built under a previous (or absent) token.
    disconnectEcho()
    setToken(response.data.token)
    setUser(response.data.user)
  }

  async function logout() {
    try {
      await api.post('/logout')
    } finally {
      disconnectEcho()
      clearToken()
      setUser(null)
    }
  }

  return (
    <AuthContext.Provider value={{ user, isLoading, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}
