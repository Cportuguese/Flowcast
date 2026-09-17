import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, clearToken, getToken, setToken } from '../lib/api'

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
    })
    setToken(response.data.token)
    setUser(response.data.user)
  }

  async function logout() {
    try {
      await api.post('/logout')
    } finally {
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
