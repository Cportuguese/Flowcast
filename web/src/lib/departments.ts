import { useEffect, useState } from 'react'
import { api } from './api'

// UDD's departments and their courses, as config/udd.php defines them.

export interface Course {
  code: string
  name: string
}

export interface Department {
  code: string
  name: string
  courses: Course[]
}

let catalog: Promise<Department[]> | null = null

/** Fetched once per page load; the list changes only when UDD's programs do. */
export function loadDepartments(): Promise<Department[]> {
  catalog ??= api
    .get<Department[]>('/departments')
    .then((response) => response.data)
    .catch((err) => {
      catalog = null
      throw err
    })
  return catalog
}

export function useDepartments(): Department[] {
  const [departments, setDepartments] = useState<Department[]>([])

  useEffect(() => {
    loadDepartments()
      .then(setDepartments)
      .catch(() => setDepartments([]))
  }, [])

  return departments
}

/** "BSIT · SITE", just "SITE" for faculty and staff, or "—". */
export function departmentLabel(person: { department?: string | null; course?: string | null }) {
  return [person.course, person.department].filter(Boolean).join(' · ') || '—'
}

/** Whole years from a YYYY-MM-DD birthdate to today. */
export function ageFrom(birthdate: string | null | undefined): number | null {
  if (!birthdate) return null
  const born = new Date(`${birthdate}T00:00:00`)
  const now = new Date()
  let age = now.getFullYear() - born.getFullYear()
  if (now.getMonth() < born.getMonth() || (now.getMonth() === born.getMonth() && now.getDate() < born.getDate())) {
    age--
  }
  return age
}
