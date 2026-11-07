import axios from 'axios';

/**
 * Field-by-field messages from a Laravel 422, so each input can show its own
 * problem ("Type your full middle name, not just an initial") right under it.
 * Empty when the error was not a validation error.
 */
export function fieldErrors(err: unknown): Record<string, string> {
  if (axios.isAxiosError(err) && err.response?.status === 422) {
    const errors = (err.response.data as { errors?: Record<string, string[]> }).errors ?? {};
    return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0] ?? '']));
  }
  return {};
}
