import axios from 'axios'

/** Message from a Laravel JSON error response, else the fallback. */
export function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string }
    return data.message ?? fallback
  }
  return fallback
}
