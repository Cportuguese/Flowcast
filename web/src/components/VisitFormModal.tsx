import axios from 'axios'
import { useState, type FormEvent } from 'react'
import { api } from '../lib/api'

interface Props {
  queueEntryId: number
  patientName: string
  onClose: () => void
  onSaved: () => void
}

export function VisitFormModal({ queueEntryId, patientName, onClose, onSaved }: Props) {
  const [complaint, setComplaint] = useState('')
  const [bloodPressure, setBloodPressure] = useState('')
  const [temperature, setTemperature] = useState('')
  const [pulse, setPulse] = useState('')
  const [respRate, setRespRate] = useState('')
  const [diagnosis, setDiagnosis] = useState('')
  const [treatment, setTreatment] = useState('')
  const [referredTo, setReferredTo] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)

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
      })
      onSaved()
    } catch (err) {
      if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as { message?: string }
        setError(data.message ?? 'Unable to save this visit record.')
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
            <textarea
              required
              value={complaint}
              onChange={(e) => setComplaint(e.target.value)}
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
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {isSaving ? 'Saving…' : 'Save record'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
