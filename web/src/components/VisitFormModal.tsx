import { useEffect, useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { buttonClass } from '../components/Button'
import { CertificatePanel } from './CertificatePanel'
import { useAuth } from '../context/AuthContext'
import type { Certificate } from '../lib/certificates'
import { extractErrorMessage } from '../lib/errors'

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
  /**
   * What the visit belongs to: a queue entry (walk-ins and the nurse's
   * booked patients) or a booking the dentist or physician saw at its time.
   */
  source: { queueEntryId: number } | { appointmentId: number }
  patientName: string
  /** Pre-filled complaint, e.g. the reason the patient gave when booking. */
  initialComplaint?: string
  /** Quick-pick complaint chips; defaults to the nurse's common walk-in complaints. */
  complaintChips?: string[]
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

const inputClass =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100'

/**
 * The record of one visit: complaint, vital signs, the intervention (what
 * was done), medicines given, referral and remarks. Medicines are stocked
 * out as the record saves.
 */
export function VisitFormModal({ source, patientName, initialComplaint = '', complaintChips = COMMON_COMPLAINTS, onClose, onSaved }: Props) {
  const { user } = useAuth()
  const [complaint, setComplaint] = useState(initialComplaint)
  const [bloodPressure, setBloodPressure] = useState('')
  const [temperature, setTemperature] = useState('')
  const [pulse, setPulse] = useState('')
  const [respRate, setRespRate] = useState('')
  const [intervention, setIntervention] = useState('')
  const [remarks, setRemarks] = useState('')
  const [referredTo, setReferredTo] = useState('')
  const [medicines, setMedicines] = useState<MedicineOption[]>([])
  const [dispensed, setDispensed] = useState<DispensedLine[]>([])
  const [error, setError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  // Set once the record is saved; whoever wrote it then gets the certificate step.
  const [savedVisit, setSavedVisit] = useState<{ id: number; certificate: Certificate | null } | null>(null)

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
      const response = await api.post<{ id: number; certificate: Certificate | null }>('/visits', {
        ...('queueEntryId' in source ? { queue_entry_id: source.queueEntryId } : { appointment_id: source.appointmentId }),
        complaint,
        blood_pressure: bloodPressure || null,
        temperature: temperature || null,
        pulse: pulse || null,
        resp_rate: respRate || null,
        intervention,
        remarks: remarks || null,
        referred_to: referredTo || null,
        medicines: dispensed.map((line) => ({ medicine_id: Number(line.medicine_id), quantity: Number(line.quantity) })),
      })
      // Any of the three professionals may issue a certificate for a visit
      // they recorded themselves.
      if (user?.role === 'admin' || user?.role === 'personnel') {
        setSavedVisit({ id: response.data.id, certificate: response.data.certificate })
      } else {
        onSaved()
      }
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to save this visit record.'))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold text-slate-900">Visit record — {patientName}</h2>
        <p className="text-xs text-slate-500">{new Date().toLocaleString()}</p>

        {savedVisit ? (
          <div className="mt-4 space-y-4">
            <p className="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
              Visit record saved. Issue a medical certificate if the patient needs one.
            </p>
            <CertificatePanel
              visitId={savedVisit.id}
              certificate={savedVisit.certificate}
              canIssue
              onIssued={(certificate) => setSavedVisit({ ...savedVisit, certificate })}
            />
            <div className="flex justify-end pt-2">
              <button type="button" onClick={onSaved} className={buttonClass.primary}>
                Done
              </button>
            </div>
          </div>
        ) : (
          <>
            {error && (
              <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
            )}

            <form onSubmit={handleSubmit} className="mt-4 space-y-4">
              <div>
                <label htmlFor="visit-complaint" className="mb-1 block text-xs font-medium text-slate-700">Complaint</label>
                <div className="mb-2 flex flex-wrap gap-1.5">
                  {complaintChips.map((label) => (
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
                  id="visit-complaint"
                  required
                  value={complaint}
                  onChange={(e) => setComplaint(e.target.value)}
                  placeholder="Tap a common complaint above, or type here"
                  rows={2}
                  className={inputClass}
                />
              </div>

              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div>
                  <label htmlFor="visit-bp" className="mb-1 block text-xs font-medium text-slate-700">BP</label>
                  <input id="visit-bp" value={bloodPressure} onChange={(e) => setBloodPressure(e.target.value)} placeholder="120/80" className={inputClass} />
                </div>
                <div>
                  <label htmlFor="visit-temp" className="mb-1 block text-xs font-medium text-slate-700">Temp (°C)</label>
                  <input id="visit-temp" value={temperature} onChange={(e) => setTemperature(e.target.value)} inputMode="decimal" placeholder="37.0" className={inputClass} />
                </div>
                <div>
                  <label htmlFor="visit-pulse" className="mb-1 block text-xs font-medium text-slate-700">Pulse</label>
                  <input id="visit-pulse" value={pulse} onChange={(e) => setPulse(e.target.value)} inputMode="numeric" placeholder="80" className={inputClass} />
                </div>
                <div>
                  <label htmlFor="visit-resp-rate" className="mb-1 block text-xs font-medium text-slate-700">Resp rate</label>
                  <input id="visit-resp-rate" value={respRate} onChange={(e) => setRespRate(e.target.value)} inputMode="numeric" placeholder="16" className={inputClass} />
                </div>
              </div>

              <div>
                <label htmlFor="visit-intervention" className="mb-1 block text-xs font-medium text-slate-700">Intervention</label>
                <textarea
                  id="visit-intervention"
                  required
                  value={intervention}
                  onChange={(e) => setIntervention(e.target.value)}
                  placeholder="What was done, e.g. Paracetamol 500 mg given; rested in the clinic."
                  rows={2}
                  className={inputClass}
                />
              </div>

              {medicines.length > 0 && (
                <div>
                  <div className="mb-1 flex items-center justify-between">
                    <span className="block text-xs font-medium text-slate-700">Medicines given</span>
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
                              aria-label="Medicine"
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
                              aria-label="Quantity"
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
                <label htmlFor="visit-referred-to" className="mb-1 block text-xs font-medium text-slate-700">Referred to (optional)</label>
                <input id="visit-referred-to" value={referredTo} onChange={(e) => setReferredTo(e.target.value)} className={inputClass} />
              </div>

              <div>
                <label htmlFor="visit-remarks" className="mb-1 block text-xs font-medium text-slate-700">Remarks (optional)</label>
                <textarea
                  id="visit-remarks"
                  value={remarks}
                  onChange={(e) => setRemarks(e.target.value)}
                  placeholder="Advice, follow-up, sent back to class…"
                  rows={2}
                  className={inputClass}
                />
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onClose} className={buttonClass.outline}>
                  Cancel
                </button>
                <button type="submit" disabled={isSaving} className={buttonClass.primary}>
                  {isSaving ? 'Saving…' : 'Save record'}
                </button>
              </div>
            </form>
          </>
        )}
      </div>
    </div>
  )
}
