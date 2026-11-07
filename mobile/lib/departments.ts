import { useEffect, useState } from 'react';
import { api } from './api';
import { fetchWithCache } from './cache';

// UDD's departments and their courses, as the API's config/udd.php lists them.

export interface Course {
  code: string;
  name: string;
}

export interface Department {
  code: string;
  name: string;
  courses: Course[];
}

let inMemory: Department[] | null = null;

/**
 * The list is public (sign-up needs it before login) and rarely changes,
 * so it is kept in memory for the session and cached for offline opens.
 */
export function useDepartments(): Department[] {
  const [departments, setDepartments] = useState<Department[]>(inMemory ?? []);

  useEffect(() => {
    if (inMemory) return;
    fetchWithCache('departments', async () => (await api.get<Department[]>('/departments')).data)
      .then(({ data }) => {
        inMemory = data;
        setDepartments(data);
      })
      .catch(() => setDepartments([]));
  }, []);

  return departments;
}

/** "BSIT · SITE", just "SITE" for faculty and staff, or "". */
export function departmentLabel(person: { department?: string | null; course?: string | null }) {
  return [person.course, person.department].filter(Boolean).join(' · ');
}

export function courseName(departments: Department[], code: string | null | undefined) {
  for (const department of departments) {
    const course = department.courses.find((c) => c.code === code);
    if (course) return course.name;
  }
  return null;
}

export function departmentName(departments: Department[], code: string | null | undefined) {
  return departments.find((d) => d.code === code)?.name ?? null;
}
