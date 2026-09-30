import axios from 'axios';

/**
 * Message from a Laravel JSON error response, else the fallback.
 *
 * Field-level errors come first. On a 422 the specific one is what the
 * patient needs to read ("The password must be at least 8 characters"), not
 * the summary. Any response with a message is used, not only a 422 — a 429
 * from the rate limiter is worth showing as itself.
 */
export function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string; errors?: Record<string, string[]> };
    const firstFieldError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return firstFieldError ?? data.message ?? fallback;
  }
  return fallback;
}
