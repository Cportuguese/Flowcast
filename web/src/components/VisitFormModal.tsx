import axios from 'axios'
import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { buttonClass } from '../components/Button'

interface MedicineOption {
  id: number
  name: string
  unit: string
  stock: number
}

interface DispensedLine {
  medicine_id: string
  quantity: string
}

interface Props {
  queueEntryId: number
  patientName: string
  onClose: () => void
  onSaved: () => void
}

const COMMON_COMPLAINTS = [
  'Headache',
  'Fever',
  'Cough/Colds',
  'Stomach ache',
  'Dizziness',
  'Nausea/Vomiting',
  'Menstrual cramps',
  'Minor cut/wound',
  'Sprain/Muscle pain',
  'Allergic reaction',
  'Toothache',
  'Anxiety/Panic',
]

export function VisitFormModal({ queueEntryId, patientName, onClose, onSaved }: Props) {
  const [complaint, setComplaint] = useState('')
  const [bloodPressure, setBloodPressure] = useState('')
  const [temperature, setTemperature] = useState('')
  const [pulse, setPulse] = useState('')
  const [respRate, setRespRate] = useState('')
  const [diagnosis, setDiagnosis] = useState('')
  const [treatment, setTreatment] = useState('')
  const [referredTo, setReferredTo] = useState('')
  const [medicines, setMedicines] = useState<MedicineOption[]>([])
  const [dispensed, setDispensed] = useState<DispensedLine[]>([])
  const [error, setError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)

  // Only medicines that can actually be handed over right now.
  useEffect(() => {
    api
      .get<MedicineOption[]>('/medicines')
      .then((res) => setMedicines(res.data.filter((m) => m.stock > 0)))
      .catch(() => setMedicines([]))
  }, [])

  function addDispensedLine() {
    const used = new Set(dispensed.map((d) => d.medicine_id))
    const next = medicines.find((m) => !used.has(String(m.id)))
    if (!next) return
    setDispensed([...dispensed, { medicine_id: String(next.id), quantity: '1' }])
  }

  function updateDispensedLine(index: number, patch: Partial<DispensedLine>) {
    setDispensed(dispensed.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  function removeDispensedLine(index: number) {
    setDispensed(dispensed.filter((_, i) => i !== index))
  }

  function toggleComplaint(label: string) {
    const tokens = complaint
      .split(',')
      .map((t) => t.trim())
      .filter(Boolean)

    const exists = tokens.some((t) => t.toLowerCase() === label.toLowerCase())
    const next = exists ? tokens.filter((t) => t.toLowerCase() !== label.toLowerCase()) : [...tokens, label]

    setComplaint(next.join(', '))
  }

  function isSelected(label: string) {
    return complaint
      .split(',')
      .map((t) => t.trim().toLowerCase())
      .includes(label.toLowerCase())
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSaving(true)

    try {
      await api.post('/visits', {
        queue_entry_id: queueEntryId,
        complaint,
        blood_pressure: bloodPressure || null,
        temperature: temperature || null,
        pulse: pulse || null,
        resp_rate: respRate || null,
        diagnosis,
        treatment: treatment || null,
        referred_to: referredTo || null,
        medicines: dispensed.map((line) => ({ medicine_id: Number(line.medicine_id), quantity: Number(line.quantity) })),
      })
      onSaved()
    } catch (err) {
      if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as { message?: string; errors?: Record<string, string[]> }
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined
        setError(firstError ?? data.message ?? 'Unable to save this visit record.')
      } else {
        setError('Unable to save this visit record.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">Visit record — {patientName}</h2>
        <p className="text-xs text-slate-500">{new Date().toLocaleString()}</p>

        {error && (
          <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
        )}

        <form onSubmit={handleSubmit} className="mt-4 space-y-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Complaint</label>
            <div className="mb-2 flex flex-wrap gap-1.5">
              {COMMON_COMPLAINTS.map((label) => (
                <button
                  key={label}
                  type="button"
                  onClick={() => toggleComplaint(label)}
                  className={`rounded-full border px-2.5 py-1 text-xs font-medium transition ${
                    isSelected(label)
                      ? 'border-blue-600 bg-blue-600 text-white'
                      : 'border-slate-300 text-slate-600 hover:bg-slate-100'
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
            <textarea
              required
              value={complaint}
              onChange={(e) => setComplaint(e.target.value)}
              placeholder="Tap a common complaint above, or type here"
              rows={2}
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </div>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">BP</label>
              <input
                value={bloodPressure}
                onChange={(e) => setBloodPressure(e.target.value)}
                placeholder="120/80"
                className="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Temp (°C)</label>
              <input
                value={temperature}
                onChange={(e) => setTemperature(e.target.value)}
                inputMode="decimal"
                placeholder="37.0"
                className="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Pulse</label>
              <input
                value={pulse}
                onChange={(e) => setPulse(e.target.value)}
                inputMode="numeric"
                placeholder="80"
                className="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700">Resp rate</label>
              <input
                value={respRate}
                onChange={(e) => setRespRate(e.target.value)}
                inputMode="numeric"
                placeholder="16"
                className="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
          </div>

          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Diagnosis</label>
            <textarea
              required
              value={diagnosis}
              onChange={(e) => setDiagnosis(e.target.value)}
              rows={2}
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Treatment</label>
            <textarea
              value={treatment}
              onChange={(e) => setTreatment(e.target.value)}
              rows={2}
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </div>

          {medicines.length > 0 && (
            <div>
              <div className="mb-1 flex items-center justify-between">
                <label className="block text-xs font-medium text-slate-700">Medicines given</label>
                <button
                  type="button"
                  onClick={addDispensedLine}
                  disabled={dispensed.length >= medicines.length}
                  className="text-xs font-medium text-blue-600 hover:underline disabled:text-slate-400 disabled:no-underline"
                >
                  + Add
                </button>
              </div>
              {dispensed.length === 0 ? (
                <p className="text-xs text-slate-400">None. Stock is deducted automatically for anything added here.</p>
              ) : (
                <div className="space-y-2">
                  {dispensed.map((line, index) => {
                    const selected = medicines.find((m) => String(m.id) === line.medicine_id)
                    const takenElsewhere = new Set(dispensed.filter((_, i) => i !== index).map((d) => d.medicine_id))
                    return (
                      <div key={index} className="flex items-center gap-2">
                        <select
                          value={line.medicine_id}
                          onChange={(e) => updateDispensedLine(index, { medicine_id: e.target.value })}
                          className="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
                        >
                          {medicines
                            .filter((m) => String(m.id) === line.medicine_id || !takenElsewhere.has(String(m.id)))
                            .map((m) => (
                              <option key={m.id} value={m.id}>
                                {m.name} ({m.stock} {m.unit} left)
                              </option>
                            ))}
                        </select>
                        <input
                          type="number"
                          min={1}
                          max={selected?.stock}
                          required
                          value={line.quantity}
                          onChange={(e) => updateDispensedLine(index, { quantity: e.target.value })}
                          className="w-20 rounded-lg border border-slate-300 px-2 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                        <span className="w-12 text-xs text-slate-500">{selected?.unit}</span>
                        <button type="button" onClick={() => removeDispensedLine(index)} className="text-xs text-slate-400 hover:text-red-600">
                          Remove
                        </button>
                      </div>
                    )
                  })}
                </div>
              )}
            </div>
          )}

          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Referred to (optional)</label>
            <input
              value={referredTo}
              onChange={(e) => setReferredTo(e.target.value)}
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={onClose}
              className={buttonClass.outline}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className={buttonClass.primary}
            >
              {isSaving ? 'Saving…' : 'Save record'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
