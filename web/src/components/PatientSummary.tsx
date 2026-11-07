import { ageFrom, departmentLabel } from '../lib/departments'

interface Props {
  patient: {
    name: string
    school_id?: string | null
    /** False while the ID number is only what the patient typed in. */
    id_verified?: boolean
    department?: string | null
    course?: string | null
    sex?: 'male' | 'female' | null
    birthdate?: string | null
  }
}

/**
 * A patient as staff lists show them: the name, then course · department,
 * ID number, and age/sex when the record has them. An ID number nobody at
 * the clinic has checked against the patient's card yet is flagged, so it
 * gets checked when they come in.
 */
export function PatientSummary({ patient }: Props) {
  const age = ageFrom(patient.birthdate)
  const details = [
    departmentLabel(patient),
    patient.school_id,
    age !== null || patient.sex ? `${age ?? '?'}${patient.sex ? ` ${patient.sex === 'male' ? 'M' : 'F'}` : ''}` : null,
  ].filter((part) => part && part !== '—')
  const idUnchecked = patient.id_verified === false && Boolean(patient.school_id)

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 items-center gap-1.5">
        <span className="truncate font-medium text-slate-900">{patient.name}</span>
        {idUnchecked && (
          <span
            title="This ID number was typed in by the patient and hasn't been checked against their UDD ID card yet."
            className="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800"
          >
            ID unchecked
          </span>
        )}
      </div>
      {details.length > 0 && <div className="truncate text-xs text-slate-500">{details.join(' · ')}</div>}
    </div>
  )
}
