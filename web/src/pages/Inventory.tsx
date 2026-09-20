import { Fragment, useCallback, useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'
import { extractErrorMessage, type Medicine } from '../lib/inventory'
import { PageHeader } from '../components/PageHeader'
import { StatusPill, type StatusTone } from '../components/StatusPill'
import { buttonClass } from '../components/Button'

interface BatchAlert {
  id: number
  medicine: { id: number; name: string; unit: string }
  batch_number: string | null
  expiry_date: string
  quantity_remaining: number
  days_until_expiry: number
}

const UNIT_SUGGESTIONS = ['tablet', 'capsule', 'ml', 'sachet', 'bottle', 'box', 'piece']

const STATUS_TONES: Record<Medicine['status'], StatusTone> = {
  ok: 'success',
  low: 'warning',
  out: 'danger',
}

const STATUS_LABELS: Record<Medicine['status'], string> = { ok: 'OK', low: 'Low', out: 'Out' }

const inputClass =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100'

export function Inventory() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const isAdmin = user?.role === 'admin'

  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [expiringSoon, setExpiringSoon] = useState<BatchAlert[]>([])
  const [expired, setExpired] = useState<BatchAlert[]>([])
  const [showArchived, setShowArchived] = useState(false)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [stockModal, setStockModal] = useState<'in' | 'out' | null>(null)
  const [medicineModal, setMedicineModal] = useState<{ editing: Medicine | null } | null>(null)
  const [expandedId, setExpandedId] = useState<number | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      const [medicinesRes, expiringRes, expiredRes] = await Promise.all([
        api.get<Medicine[]>('/medicines', { params: { include_inactive: isAdmin && showArchived ? 1 : undefined } }),
        api.get<{ days: number; batches: BatchAlert[] }>('/inventory/expiring-soon'),
        isAdmin ? api.get<BatchAlert[]>('/admin/inventory/expired') : Promise.resolve({ data: [] as BatchAlert[] }),
      ])
      setMedicines(medicinesRes.data)
      setExpiringSoon(expiringRes.data.batches)
      setExpired(expiredRes.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to load inventory.'))
    } finally {
      setIsLoading(false)
    }
  }, [isAdmin, showArchived])

  useEffect(() => {
    void load()
  }, [load])

  async function handleArchiveToggle(medicine: Medicine) {
    try {
      await api.patch(`/admin/medicines/${medicine.id}`, { is_active: !medicine.is_active })
      await load()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to update medicine.'))
    }
  }

  async function handleDispose(batch: BatchAlert) {
    if (!window.confirm(`Dispose ${batch.quantity_remaining} ${batch.medicine.unit} of ${batch.medicine.name} (expired ${batch.expiry_date})?`)) {
      return
    }
    try {
      await api.post(`/admin/inventory/batches/${batch.id}/dispose`)
      await load()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to dispose batch.'))
    }
  }

  const lowStock = medicines.filter((m) => m.is_active && m.status !== 'ok')
  const activeMedicines = medicines.filter((m) => m.is_active)

  return (
    <>
      <div className="mx-auto max-w-5xl">
        <PageHeader title="Inventory" />

        {error && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

        {lowStock.length > 0 && (
          <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <span className="font-semibold">Low stock:</span>{' '}
            {lowStock.map((m) => `${m.name} (${m.stock} ${m.unit})`).join(', ')}
          </div>
        )}

        {expiringSoon.length > 0 && (
          <div className="mt-3 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
            <span className="font-semibold">Expiring within 30 days:</span>{' '}
            {expiringSoon
              .map((b) => `${b.medicine.name}${b.batch_number ? ` [${b.batch_number}]` : ''} — ${b.quantity_remaining} ${b.medicine.unit} on ${b.expiry_date}`)
              .join(', ')}
          </div>
        )}

        <div className="mt-6 flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => setStockModal('in')}
            className={buttonClass.primary}
          >
            Stock in
          </button>
          <button
            type="button"
            onClick={() => setStockModal('out')}
            className={buttonClass.outline}
          >
            Stock out
          </button>
          {isAdmin && (
            <>
              <button
                type="button"
                onClick={() => setMedicineModal({ editing: null })}
                className={buttonClass.outline}
              >
                Add medicine
              </button>
              <button
                type="button"
                onClick={() => navigate('/admin/inventory/history')}
                className={buttonClass.outline}
              >
                History
              </button>
              <label className="ml-auto flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} />
                Show archived
              </label>
            </>
          )}
        </div>

        <div className="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">Medicine</th>
                <th className="px-4 py-3">Unit</th>
                <th className="px-4 py-3 text-right">Stock</th>
                <th className="px-4 py-3 text-right">Reorder at</th>
                <th className="px-4 py-3">Next expiry</th>
                <th className="px-4 py-3">Status</th>
                {isAdmin && <th className="px-4 py-3" />}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-slate-400">Loading…</td>
                </tr>
              )}
              {!isLoading && medicines.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                    {isAdmin ? 'No medicines yet. Add one to get started.' : 'No medicines in the catalog yet.'}
                  </td>
                </tr>
              )}
              {medicines.map((m) => (
                <Fragment key={m.id}>
                  <tr
                    onClick={() => setExpandedId(expandedId === m.id ? null : m.id)}
                    className={`cursor-pointer hover:bg-slate-50 ${m.is_active ? '' : 'text-slate-400'}`}
                  >
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-900">
                        {m.name}
                        {!m.is_active && <span className="ml-2 text-xs font-normal text-slate-400">(archived)</span>}
                      </div>
                      {m.category && <div className="text-xs text-slate-500">{m.category}</div>}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{m.unit}</td>
                    <td className="px-4 py-3 text-right font-semibold text-slate-900">{m.stock}</td>
                    <td className="px-4 py-3 text-right text-slate-600">{m.reorder_level}</td>
                    <td className="px-4 py-3 text-slate-600">{m.next_expiry ?? '—'}</td>
                    <td className="px-4 py-3">
                      <StatusPill tone={STATUS_TONES[m.status]}>
                        {STATUS_LABELS[m.status]}
                      </StatusPill>
                      {m.expired_quantity > 0 && (
                        <span className="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700">
                          {m.expired_quantity} expired
                        </span>
                      )}
                    </td>
                    {isAdmin && (
                      <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                        <button type="button" onClick={() => setMedicineModal({ editing: m })} className="text-xs font-medium text-blue-600 hover:underline">
                          Edit
                        </button>
                        <button type="button" onClick={() => handleArchiveToggle(m)} className="ml-3 text-xs font-medium text-slate-500 hover:underline">
                          {m.is_active ? 'Archive' : 'Restore'}
                        </button>
                      </td>
                    )}
                  </tr>
                  {expandedId === m.id && (
                    <tr className="bg-slate-50">
                      <td colSpan={7} className="px-4 py-3 text-xs text-slate-600">
                        {m.batches.length === 0 ? (
                          'No usable batches.'
                        ) : (
                          <ul className="flex flex-wrap gap-x-6 gap-y-1">
                            {m.batches.map((b) => (
                              <li key={b.id}>
                                <span className="font-medium text-slate-800">{b.batch_number ?? `Batch #${b.id}`}</span> · {b.quantity_remaining} {m.unit} · expires {b.expiry_date}
                              </li>
                            ))}
                          </ul>
                        )}
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>

        {isAdmin && expired.length > 0 && (
          <div className="mt-6">
            <h2 className="text-sm font-semibold text-slate-900">Expired stock to dispose</h2>
            <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <table className="w-full text-left text-sm">
                <tbody className="divide-y divide-slate-100">
                  {expired.map((b) => (
                    <tr key={b.id}>
                      <td className="px-4 py-3 font-medium text-slate-900">{b.medicine.name}</td>
                      <td className="px-4 py-3 text-slate-600">{b.batch_number ?? `Batch #${b.id}`}</td>
                      <td className="px-4 py-3 text-slate-600">{b.quantity_remaining} {b.medicine.unit}</td>
                      <td className="px-4 py-3 text-red-700">Expired {b.expiry_date}</td>
                      <td className="px-4 py-3 text-right">
                        <button type="button" onClick={() => handleDispose(b)} className="text-xs font-medium text-red-600 hover:underline">
                          Dispose
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {stockModal && (
        <StockModal
          mode={stockModal}
          medicines={activeMedicines}
          onClose={() => setStockModal(null)}
          onSaved={() => {
            setStockModal(null)
            void load()
          }}
        />
      )}

      {medicineModal && (
        <MedicineFormModal
          editing={medicineModal.editing}
          onClose={() => setMedicineModal(null)}
          onSaved={() => {
            setMedicineModal(null)
            void load()
          }}
        />
      )}
    </>
  )
}

function StockModal({
  mode,
  medicines,
  onClose,
  onSaved,
}: {
  mode: 'in' | 'out'
  medicines: Medicine[]
  onClose: () => void
  onSaved: () => void
}) {
  const [medicineId, setMedicineId] = useState(medicines[0] ? String(medicines[0].id) : '')
  const [quantity, setQuantity] = useState('')
  const [batchNumber, setBatchNumber] = useState('')
  const [expiryDate, setExpiryDate] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)

  const selected = medicines.find((m) => String(m.id) === medicineId)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSaving(true)
    try {
      if (mode === 'in') {
        await api.post('/inventory/stock-in', {
          medicine_id: Number(medicineId),
          quantity: Number(quantity),
          batch_number: batchNumber || null,
          expiry_date: expiryDate,
          notes: notes || null,
        })
      } else {
        await api.post('/inventory/stock-out', {
          medicine_id: Number(medicineId),
          quantity: Number(quantity),
          notes: notes || null,
        })
      }
      onSaved()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to save.'))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">{mode === 'in' ? 'Stock in' : 'Stock out'}</h2>
        <p className="text-xs text-slate-500">
          {mode === 'in' ? 'Record a delivery as a new batch.' : 'Routine depletion — oldest expiry is used first. Medicines given to a patient are recorded on the visit form instead.'}
        </p>

        {error && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

        <form onSubmit={handleSubmit} className="mt-4 space-y-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Medicine</label>
            <select required value={medicineId} onChange={(e) => setMedicineId(e.target.value)} className={inputClass}>
              {medicines.length === 0 && <option value="">No medicines available</option>}
              {medicines.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name} — {m.stock} {m.unit} on hand
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">
              Quantity{selected ? ` (${selected.unit})` : ''}
            </label>
            <input
              required
              type="number"
              min={1}
              max={mode === 'out' && selected ? selected.stock : undefined}
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
              className={inputClass}
            />
          </div>
          {mode === 'in' && (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-700">Batch / lot no. (optional)</label>
                <input value={batchNumber} onChange={(e) => setBatchNumber(e.target.value)} className={inputClass} />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-700">Expiry date</label>
                <input required type="date" value={expiryDate} onChange={(e) => setExpiryDate(e.target.value)} className={inputClass} />
              </div>
            </div>
          )}
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Notes (optional)</label>
            <input
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder={mode === 'in' ? 'e.g. Delivery from supplier' : 'e.g. First-aid kit refill'}
              className={inputClass}
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className={buttonClass.outline}>
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving || medicines.length === 0}
              className={buttonClass.primary}
            >
              {isSaving ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

function MedicineFormModal({ editing, onClose, onSaved }: { editing: Medicine | null; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(editing?.name ?? '')
  const [category, setCategory] = useState(editing?.category ?? '')
  const [unit, setUnit] = useState(editing?.unit ?? 'tablet')
  const [reorderLevel, setReorderLevel] = useState(editing ? String(editing.reorder_level) : '10')
  const [reorderQuantity, setReorderQuantity] = useState(editing?.reorder_quantity ? String(editing.reorder_quantity) : '')
  const [error, setError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSaving(true)
    const payload = {
      name,
      category: category || null,
      unit,
      reorder_level: Number(reorderLevel),
      reorder_quantity: reorderQuantity ? Number(reorderQuantity) : null,
    }
    try {
      if (editing) {
        await api.patch(`/admin/medicines/${editing.id}`, payload)
      } else {
        await api.post('/admin/medicines', payload)
      }
      onSaved()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to save medicine.'))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">{editing ? 'Edit medicine' : 'Add medicine'}</h2>

        {error && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

        <form onSubmit={handleSubmit} className="mt-4 space-y-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Name</label>
            <input required value={name} onChange={(e) => setName(e.target.value)} placeholder="Paracetamol 500mg" className={inputClass} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Category (optional)</label>
              <input value={category} onChange={(e) => setCategory(e.target.value)} placeholder="Analgesic" className={inputClass} />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Unit</label>
              <input required list="unit-suggestions" value={unit} onChange={(e) => setUnit(e.target.value)} maxLength={20} className={inputClass} />
              <datalist id="unit-suggestions">
                {UNIT_SUGGESTIONS.map((u) => (
                  <option key={u} value={u} />
                ))}
              </datalist>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Reorder when stock ≤</label>
              <input required type="number" min={0} value={reorderLevel} onChange={(e) => setReorderLevel(e.target.value)} className={inputClass} />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Usual reorder qty (optional)</label>
              <input type="number" min={1} value={reorderQuantity} onChange={(e) => setReorderQuantity(e.target.value)} className={inputClass} />
            </div>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className={buttonClass.outline}>
              Cancel
            </button>
            <button type="submit" disabled={isSaving} className={buttonClass.primary}>
              {isSaving ? 'Saving…' : editing ? 'Save changes' : 'Add medicine'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
