import axios from 'axios'

const TOKEN_KEY = 'flowcast_token'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api',
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Registered by AuthProvider so an expired/revoked token clears React state
// as well as storage. Skips /login and /logout: a 401 there is either a bad
// attempt or an already-dead token, and the caller handles both itself.
let unauthorizedHandler: (() => void) | null = null

export function onUnauthorized(handler: () => void) {
  unauthorizedHandler = handler
}

api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (axios.isAxiosError(error) && error.response?.status === 401) {
      const url = error.config?.url ?? ''
      const isAuthRoute = /\/(login|logout|register)$/.test(url)

      if (!isAuthRoute && localStorage.getItem(TOKEN_KEY)) {
        clearToken()
        unauthorizedHandler?.()
      }
    }

    return Promise.reject(error)
  },
)

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}
