import axios from 'axios'

export interface Medicine {
  id: number
  name: string
  category: string | null
  unit: string
  reorder_level: number
  reorder_quantity: number | null
  is_active: boolean
  stock: number
  status: 'ok' | 'low' | 'out'
  next_expiry: string | null
  expired_quantity: number
  batches: Array<{ id: number; batch_number: string | null; expiry_date: string; quantity_remaining: number }>
}

// Surfaces the first field-level validation message (e.g. the FIFO
// shortfall on `quantity`) before falling back to the generic message.
export function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string; errors?: Record<string, string[]> }
    const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return firstError ?? data.message ?? fallback
  }
  return fallback
}
