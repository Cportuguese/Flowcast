import axios from 'axios'

/**
 * Message from a Laravel JSON error response, else the fallback.
 *
 * Field-level errors come first. On a 422 the specific one — a FIFO stock
 * shortfall reported on `quantity`, say — is what the person needs to read,
 * and Laravel's summary `message` only ever quotes the first of them anyway.
 */
export function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string; errors?: Record<string, string[]> }
    const firstFieldError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return firstFieldError ?? data.message ?? fallback
  }
  return fallback
}
