import { useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { formatBirthdate, formatTime } from '../lib/dates'
import { departmentLabel, useDepartments } from '../lib/departments'
import { extractErrorMessage } from '../lib/errors'
import { buttonClass } from './Button'

interface FoundPatient {
  id: number
  name: string
  school_id: string | null
  /** False while the number is only what the patient typed into the app. */
  id_verified: boolean
  department: string | null
  course: string | null
  birthdate: string | null
  sex: 'male' | 'female' | null
  /** Null for records the nurse registered at the counter. */
  email: string | null
  registered_on: string | null
}

/** Their confirmed booking with the nurse today, if any. */
interface BookingToday {
  id: number
  scheduled_at: string
}

interface LookupResult {
  patient: FoundPatient | null
  inQueue: boolean
  bookingToday: BookingToday | null
}

interface Props {
  onCheckedIn: () => void
}

type PatientType = 'student' | 'faculty' | 'staff'

const inputClass =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100'

const labelClass = 'mb-1 block text-xs font-medium text-slate-700'

/**
 * Checking in someone at the counter. The ID number comes first: a patient
 * who already has a record (from the app, or an earlier walk-in) is checked
 * in on that record instead of getting a duplicate. Only someone new gets
 * the short form — enough to queue them and count them by department.
 *
 * A number typed into the app by the patient is only a claim, so the first
 * time it comes up the nurse compares the record with the person and their
 * UDD ID card. If they don't match, the number comes off that account and
 * the person in front of her is registered with it instead.
 */
export function WalkInPanel({ onCheckedIn }: Props) {
  const departments = useDepartments()

  const [idNumber, setIdNumber] = useState('')
  const [found, setFound] = useState<LookupResult | null>(null)
  const [showForm, setShowForm] = useState(false)
  const [isBusy, setIsBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [firstName, setFirstName] = useState('')
  const [middleName, setMiddleName] = useState('')
  const [lastName, setLastName] = useState('')
  const [patientType, setPatientType] = useState<PatientType>('student')
  const [course, setCourse] = useState('')
  const [department, setDepartment] = useState('')
  const [sex, setSex] = useState<'' | 'male' | 'female'>('')
  const [contactNo, setContactNo] = useState('')

  async function handleFind(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsBusy(true)
    try {
      const response = await api.get<{ patient: FoundPatient | null; in_queue: boolean; booking_today: BookingToday | null }>(
        '/admin/queue/lookup',
        { params: { school_id: idNumber.trim() } },
      )
      setFound({ patient: response.data.patient, inQueue: response.data.in_queue, bookingToday: response.data.booking_today })
      setShowForm(response.data.patient === null)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to look up this ID number.'))
    } finally {
      setIsBusy(false)
    }
  }

  // On their booking, if they have one with the nurse today, so they keep
  // their place at the booked time instead of joining as a walk-in. An
  // unchecked number is marked checked first: the nurse just compared it.
  async function checkInFound(patient: FoundPatient, booking: BookingToday | null) {
    setError(null)
    setIsBusy(true)
    try {
      if (!patient.id_verified) await api.patch(`/admin/patients/${patient.id}/verify-id`)
      await api.post('/queue/check-in', { patient_id: patient.id, appointment_id: booking?.id ?? null })
      onCheckedIn()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to check this patient in.'))
    } finally {
      setIsBusy(false)
    }
  }

  // The account holding this number is not the person at the counter: take
  // the number off it, then register the real owner with it.
  async function releaseId(patient: FoundPatient) {
    const sure = window.confirm(
      `Take ID number ${patient.school_id} off ${patient.name}'s account?\n\n` +
        'Only do this if the person at the counter is the real owner of this ID card. ' +
        "That account keeps its past visits but can't book again until it gives an ID number of its own.",
    )
    if (!sure) return

    setError(null)
    setIsBusy(true)
    try {
      await api.patch(`/admin/patients/${patient.id}/release-id`)
      setFound(null)
      setShowForm(true)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to take the ID number off that account.'))
    } finally {
      setIsBusy(false)
    }
  }

  async function handleRegister(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsBusy(true)
    try {
      await api.post('/admin/queue/walk-in', {
        first_name: firstName,
        middle_name: middleName || null,
        last_name: lastName,
        patient_type: patientType,
        course: patientType === 'student' ? course || null : null,
        department: patientType === 'student' ? null : department || null,
        school_id: idNumber.trim() || null,
        sex: sex || null,
        contact_no: contactNo || null,
      })
      onCheckedIn()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to register this walk-in.'))
    } finally {
      setIsBusy(false)
    }
  }

  return (
    <div className="mt-4 space-y-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
      <div>
        <h2 className="text-sm font-semibold text-slate-900">Walk-in check-in</h2>
        <p className="text-xs text-slate-500">Start with the ID number, so a registered patient keeps one record.</p>
      </div>

      {error && <p role="alert" className="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{error}</p>}

      <form onSubmit={handleFind} className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1">
          <label htmlFor="walkin-id" className={labelClass}>ID number</label>
          <input
            id="walkin-id"
            value={idNumber}
            onChange={(e) => {
              setIdNumber(e.target.value)
              setFound(null)
            }}
            placeholder="e.g. 23-1057-651"
            className={inputClass}
          />
        </div>
        <button type="submit" disabled={isBusy || !idNumber.trim()} className={buttonClass.primary}>
          Find
        </button>
        <button
          type="button"
          onClick={() => {
            // "Without one" means without one: a number still in the box
            // would otherwise be registered with the record.
            setIdNumber('')
            setFound(null)
            setShowForm(true)
          }}
          className={buttonClass.ghost}
        >
          No ID? Register without one
        </button>
      </form>

      {found?.patient && (
        <div className="space-y-3 rounded-lg bg-slate-50 px-4 py-3">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-sm font-medium text-slate-900">{found.patient.name}</p>
              <p className="text-xs text-slate-500">
                {departmentLabel(found.patient)} · {found.patient.school_id}
              </p>
              <p className="text-xs text-slate-500">
                {[
                  found.patient.birthdate && `Born ${formatBirthdate(found.patient.birthdate)}`,
                  found.patient.sex && (found.patient.sex === 'male' ? 'Male' : 'Female'),
                  found.patient.email ?? 'Registered at the counter',
                  found.patient.email && found.patient.registered_on && `signed up ${formatBirthdate(found.patient.registered_on)}`,
                ]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
              {found.bookingToday && !found.inQueue && (
                <p className="mt-1 text-xs font-medium text-blue-700">
                  Booked with you at {formatTime(found.bookingToday.scheduled_at)} — checking in keeps that place.
                </p>
              )}
            </div>
            {found.inQueue ? (
              <span className="text-sm text-slate-500">Already in today's queue.</span>
            ) : (
              <button
                type="button"
                disabled={isBusy}
                onClick={() => checkInFound(found.patient!, found.bookingToday)}
                className={buttonClass.primary}
              >
                {found.patient.id_verified ? 'Check in' : 'Card matches — check in'}
              </button>
            )}
          </div>

          {!found.patient.id_verified && (
            <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
              This ID number was typed in by the patient and hasn't been checked yet. Compare their UDD ID card and ask
              their date of birth before checking them in.
            </p>
          )}

          <button
            type="button"
            disabled={isBusy}
            onClick={() => releaseId(found.patient!)}
            className="text-xs font-medium text-red-600 hover:underline disabled:opacity-50"
          >
            Not this person? Take the ID number off this account
          </button>
        </div>
      )}

      {found && !found.patient && (
        <p className="text-sm text-slate-600">No patient has this ID number yet — fill in the details to register them.</p>
      )}

      {showForm && (
        <form onSubmit={handleRegister} className="grid gap-3 sm:grid-cols-3">
          <div>
            <label htmlFor="walkin-first" className={labelClass}>First name</label>
            <input id="walkin-first" required value={firstName} onChange={(e) => setFirstName(e.target.value)} className={inputClass} />
          </div>
          <div>
            <label htmlFor="walkin-middle" className={labelClass}>
              Middle name <span className="font-normal text-slate-400">(full, not initial)</span>
            </label>
            <input id="walkin-middle" value={middleName} onChange={(e) => setMiddleName(e.target.value)} className={inputClass} />
          </div>
          <div>
            <label htmlFor="walkin-last" className={labelClass}>Last name</label>
            <input id="walkin-last" required value={lastName} onChange={(e) => setLastName(e.target.value)} className={inputClass} />
          </div>
          <div>
            <label htmlFor="walkin-type" className={labelClass}>Patient type</label>
            <select id="walkin-type" value={patientType} onChange={(e) => setPatientType(e.target.value as PatientType)} className={inputClass}>
              <option value="student">Student</option>
              <option value="faculty">Faculty</option>
              <option value="staff">School Employee</option>
            </select>
          </div>
          {patientType === 'student' ? (
            <div className="sm:col-span-2">
              <label htmlFor="walkin-course" className={labelClass}>Course</label>
              <select id="walkin-course" required value={course} onChange={(e) => setCourse(e.target.value)} className={inputClass}>
                <option value="">Select a course…</option>
                {departments
                  .filter((dept) => dept.courses.length > 0)
                  .map((dept) => (
                    <optgroup key={dept.code} label={`${dept.code} — ${dept.name}`}>
                      {dept.courses.map((c) => (
                        <option key={c.code} value={c.code}>
                          {c.code} — {c.name}
                        </option>
                      ))}
                    </optgroup>
                  ))}
              </select>
            </div>
          ) : (
            <div className="sm:col-span-2">
              <label htmlFor="walkin-department" className={labelClass}>Department</label>
              <select id="walkin-department" required value={department} onChange={(e) => setDepartment(e.target.value)} className={inputClass}>
                <option value="">Select a department…</option>
                {departments.map((dept) => (
                  <option key={dept.code} value={dept.code}>
                    {dept.code} — {dept.name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <div>
            <label htmlFor="walkin-sex" className={labelClass}>Sex</label>
            <select id="walkin-sex" value={sex} onChange={(e) => setSex(e.target.value as typeof sex)} className={inputClass}>
              <option value="">—</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>
          <div>
            <label htmlFor="walkin-contact" className={labelClass}>Contact number</label>
            <input id="walkin-contact" value={contactNo} onChange={(e) => setContactNo(e.target.value)} className={inputClass} />
          </div>
          <div className="flex items-end">
            <button type="submit" disabled={isBusy} className={`${buttonClass.primary} w-full`}>
              {isBusy ? 'Checking in…' : 'Register and check in'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
