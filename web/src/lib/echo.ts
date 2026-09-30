import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getToken } from './api'

const apiUrl = import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api'
const authEndpoint = `${apiUrl.replace(/\/api\/?$/, '')}/broadcasting/auth`

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

window.Pusher = Pusher

let echo: Echo<'reverb'> | null = null

export function getEcho() {
  if (!echo) {
    echo = new Echo({
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_REVERB_HOST,
      // pusher-js dials wsPort for ws:// but wssPort for wss://, which
      // defaults to 443 — so without both, TLS on any other port is ignored.
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
      forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint,
      auth: {
        headers: {
          Authorization: `Bearer ${getToken()}`,
          Accept: 'application/json',
        },
      },
    })
  }

  return echo
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
}
