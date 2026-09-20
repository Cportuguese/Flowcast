import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { extractErrorMessage, type Medicine } from '../lib/inventory'
import { PageHeader } from '../components/PageHeader'
import { StatusPill, type StatusTone } from '../components/StatusPill'
import { buttonClass } from '../components/Button'

interface InventoryLogEntry {
  id: number
  created_at: string
  type: 'stock_in' | 'stock_out' | 'disposed'
  quantity: number
  medicine: { id: number; name: string; unit: string }
  batch: { id: number; batch_number: string | null; expiry_date: string } | null
  user: { id: number; name: string } | null
  visit: { id: number; queue_entry_id: number; patient_name: string | null } | null
  notes: string | null
}

const TYPE_LABELS: Record<InventoryLogEntry['type'], string> = {
  stock_in: 'Stock in',
  stock_out: 'Stock out',
  disposed: 'Disposed',
}

const TYPE_TONES: Record<InventoryLogEntry['type'], StatusTone> = {
  stock_in: 'success',
  stock_out: 'info',
  disposed: 'neutral',
}

function downloadCsv(filename: string, rows: Array<Array<string | number | null>>) {
  const csv = rows
    .map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
    .join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

export function InventoryHistory() {

  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [medicineId, setMedicineId] = useState('')
  const [type, setType] = useState('')

  const [results, setResults] = useState<InventoryLogEntry[] | null>(null)
  const [isSearching, setIsSearching] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .get<Medicine[]>('/medicines', { params: { include_inactive: 1 } })
      .then((res) => setMedicines(res.data))
      .catch(() => setMedicines([]))
  }, [])

  async function handleSearch(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSearching(true)
    try {
      const response = await api.get<InventoryLogEntry[]>('/admin/inventory/history', {
        params: {
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
          medicine_id: medicineId || undefined,
          type: type || undefined,
        },
      })
      setResults(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Search failed.'))
    } finally {
      setIsSearching(false)
    }
  }

  function handleExport() {
    if (!results) return
    const header = ['Date/Time', 'Type', 'Medicine', 'Quantity', 'Unit', 'Batch', 'Batch expiry', 'By', 'Visit', 'Notes']
    const rows = results.map((log) => [
      new Date(log.created_at).toLocaleString(),
      TYPE_LABELS[log.type],
      log.medicine.name,
      log.type === 'stock_in' ? log.quantity : -log.quantity,
      log.medicine.unit,
      log.batch?.batch_number ?? (log.batch ? `#${log.batch.id}` : ''),
      log.batch?.expiry_date ?? '',
      log.user?.name ?? 'System',
      log.visit ? `Visit #${log.visit.id}${log.visit.patient_name ? ` — ${log.visit.patient_name}` : ''}` : '',
      log.notes ?? '',
    ])
    downloadCsv(`inventory-history-${dateFrom || 'all'}-to-${dateTo || todayIso()}.csv`, [header, ...rows])
  }

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Inventory History" />

      <form onSubmit={handleSearch} className="mt-6 flex flex-wrap items-end gap-3">
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">From</label>
          <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">To</label>
          <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">Medicine</label>
          <select value={medicineId} onChange={(e) => setMedicineId(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All</option>
            {medicines.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
                {m.is_active ? '' : ' (archived)'}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">Type</label>
          <select value={type} onChange={(e) => setType(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All</option>
            <option value="stock_in">Stock in</option>
            <option value="stock_out">Stock out</option>
            <option value="disposed">Disposed</option>
          </select>
        </div>
        <button type="submit" disabled={isSearching} className={buttonClass.primary}>
          {isSearching ? 'Searching…' : 'Search'}
        </button>
        {results && results.length > 0 && (
          <button type="button" onClick={handleExport} className={buttonClass.outline}>
            Download CSV
          </button>
        )}
      </form>

      {error && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

      {results && (
        <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">Date/Time</th>
                <th className="px-4 py-3">Type</th>
                <th className="px-4 py-3">Medicine</th>
                <th className="px-4 py-3 text-right">Qty</th>
                <th className="px-4 py-3">Batch</th>
                <th className="px-4 py-3">By</th>
                <th className="px-4 py-3">Reference</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {results.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-slate-400">No transactions found.</td>
                </tr>
              )}
              {results.map((log) => (
                <tr key={log.id}>
                  <td className="px-4 py-3 text-slate-500">{new Date(log.created_at).toLocaleString()}</td>
                  <td className="px-4 py-3">
                    <StatusPill tone={TYPE_TONES[log.type]}>{TYPE_LABELS[log.type]}</StatusPill>
                  </td>
                  <td className="px-4 py-3 font-medium text-slate-900">{log.medicine.name}</td>
                  <td className={`px-4 py-3 text-right font-semibold ${log.type === 'stock_in' ? 'text-green-700' : 'text-slate-700'}`}>
                    {log.type === 'stock_in' ? '+' : '−'}
                    {log.quantity} <span className="font-normal text-slate-500">{log.medicine.unit}</span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {log.batch ? (
                      <>
                        {log.batch.batch_number ?? `#${log.batch.id}`}
                        <div className="text-xs text-slate-400">exp. {log.batch.expiry_date}</div>
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{log.user?.name ?? 'System'}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {log.visit ? (
                      <>
                        Visit #{log.visit.id}
                        {log.visit.patient_name && <div className="text-xs text-slate-400">{log.visit.patient_name}</div>}
                      </>
                    ) : (
                      log.notes ?? '—'
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
