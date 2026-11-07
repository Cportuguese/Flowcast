import { useState, type FormEvent } from 'react'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'
import { downloadCertificate, type Certificate } from '../lib/certificates'
import { extractErrorMessage } from '../lib/errors'
import { Button } from './Button'

interface Props {
  visitId: number
  certificate: Certificate | null
  /**
   * Whether this user may issue one for this visit: the head nurse for any
   * visit, the dentist and physician for the visits they recorded. The API
   * enforces the same rule; this only decides whether to offer the form.
   */
  canIssue?: boolean
  onIssued: (certificate: Certificate) => void
}

const inputClass =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100'

/**
 * Medical certificate for one visit. Whoever may issue it sees a small form
 * until one exists; everyone with access to the visit sees the download
 * once it does.
 */
export function CertificatePanel({ visitId, certificate, canIssue: canIssueProp, onIssued }: Props) {
  const { user } = useAuth()
  const canIssue = canIssueProp ?? user?.role === 'admin'

  const [restDays, setRestDays] = useState('0')
  const [remarks, setRemarks] = useState('')
  const [isBusy, setIsBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleIssue(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsBusy(true)
    try {
      const response = await api.post<Certificate>(`/visits/${visitId}/certificate`, {
        rest_days: Number(restDays) || 0,
        remarks: remarks.trim() || null,
      })
      onIssued(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to issue the certificate.'))
    } finally {
      setIsBusy(false)
    }
  }

  async function handleDownload() {
    if (!certificate) return
    setError(null)
    setIsBusy(true)
    try {
      await downloadCertificate(certificate)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to download the certificate.'))
    } finally {
      setIsBusy(false)
    }
  }

  if (certificate) {
    return (
      <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p className="text-xs font-medium uppercase text-slate-500">Medical certificate</p>
        <p className="mt-1 text-sm text-slate-900">
          <span className="font-semibold">{certificate.certificate_no}</span>
          {' · '}
          {certificate.rest_days
            ? `${certificate.rest_days} rest ${certificate.rest_days === 1 ? 'day' : 'days'}`
            : 'fit to return'}
        </p>
        <p className="text-xs text-slate-500">
          Issued by {certificate.issued_by} on {new Date(certificate.issued_at).toLocaleDateString()}
        </p>
        {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
        <Button variant="outline" onClick={handleDownload} disabled={isBusy} className="mt-3">
          {isBusy ? 'Preparing…' : 'Download PDF'}
        </Button>
      </div>
    )
  }

  if (!canIssue) {
    return <p className="text-sm text-slate-400">No medical certificate issued for this visit.</p>
  }

  return (
    <form onSubmit={handleIssue} className="rounded-lg border border-slate-200 bg-slate-50 p-4">
      <p className="text-xs font-medium uppercase text-slate-500">Medical certificate</p>
      {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
      <div className="mt-3 grid gap-3 sm:grid-cols-[8rem_1fr]">
        <div>
          <label htmlFor={`rest-days-${visitId}`} className="mb-1 block text-xs font-medium text-slate-700">
            Rest days
          </label>
          <input
            id={`rest-days-${visitId}`}
            type="number"
            min={0}
            max={30}
            value={restDays}
            onChange={(e) => setRestDays(e.target.value)}
            className={inputClass}
          />
          <p className="mt-1 text-xs text-slate-400">0 = fit to return</p>
        </div>
        <div>
          <label htmlFor={`remarks-${visitId}`} className="mb-1 block text-xs font-medium text-slate-700">
            Remarks <span className="font-normal text-slate-400">(optional)</span>
          </label>
          <textarea
            id={`remarks-${visitId}`}
            rows={2}
            value={remarks}
            onChange={(e) => setRemarks(e.target.value)}
            className={inputClass}
          />
        </div>
      </div>
      <Button type="submit" disabled={isBusy} className="mt-3">
        {isBusy ? 'Generating…' : 'Generate medical certificate'}
      </Button>
    </form>
  )
}
