import axios from 'axios'
import { useState, type FormEvent } from 'react'
import { Button } from '../components/Button'
import { PageHeader } from '../components/PageHeader'
import { useToast } from '../components/Toast'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'

const inputClass =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100'

// Every role lands here: staff accounts start with the random password
// `make:staff` printed, so this is the only way they get their own.
export function Account() {
  const { user } = useAuth()
  const toast = useToast()

  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      await api.patch('/user/password', {
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      })
      setCurrentPassword('')
      setPassword('')
      setConfirmation('')
      toast('Password updated. Other devices were signed out.')
    } catch (err) {
      if (axios.isAxiosError(err) && err.response?.status === 422) {
        const data = err.response.data as { message?: string; errors?: Record<string, string[]> }
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined
        setError(firstError ?? data.message ?? 'Unable to update the password.')
      } else {
        setError('Unable to update the password.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="mx-auto max-w-xl">
      <PageHeader title="Account" />

      <section className="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt className="text-slate-500">Name</dt>
          <dd className="font-medium text-slate-900">{user?.name}</dd>
          <dt className="text-slate-500">Email</dt>
          <dd className="text-slate-900">{user?.email}</dd>
          <dt className="text-slate-500">Role</dt>
          <dd className="capitalize text-slate-900">{user?.role}</dd>
        </dl>
        <p className="mt-4 text-xs text-slate-400">
          Name and email are managed by the clinic administrator.
        </p>
      </section>

      <form
        onSubmit={handleSubmit}
        className="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
      >
        <h2 className="text-base font-semibold text-slate-900">Change password</h2>

        {error && (
          <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {error}
          </p>
        )}

        <div className="mt-4 space-y-4">
          <div>
            <label htmlFor="current-password" className="mb-1 block text-sm font-medium text-slate-700">
              Current password
            </label>
            <input
              id="current-password"
              type="password"
              required
              autoComplete="current-password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              className={inputClass}
            />
          </div>
          <div>
            <label htmlFor="new-password" className="mb-1 block text-sm font-medium text-slate-700">
              New password
            </label>
            <input
              id="new-password"
              type="password"
              required
              minLength={8}
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className={inputClass}
            />
            <p className="mt-1 text-xs text-slate-400">At least 8 characters.</p>
          </div>
          <div>
            <label htmlFor="confirm-password" className="mb-1 block text-sm font-medium text-slate-700">
              Confirm new password
            </label>
            <input
              id="confirm-password"
              type="password"
              required
              autoComplete="new-password"
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              className={inputClass}
            />
          </div>
        </div>

        <div className="mt-6 flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : 'Update password'}
          </Button>
        </div>
      </form>
    </div>
  )
}
