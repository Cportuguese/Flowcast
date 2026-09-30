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
