import { lazy, Suspense } from 'react'
import { PageLoading } from '../components/PageLoading'
import { PatientWebNotice } from '../components/PatientWebNotice'
import { useAuth } from '../context/AuthContext'
import { PersonnelHome } from './home/PersonnelHome'

// AdminHome pulls in recharts; personnel never render it, so keep it out
// of their bundle.
const AdminHome = lazy(() => import('./home/AdminHome').then((m) => ({ default: m.AdminHome })))

// One route, three homes: oversight for admin, queue-first for personnel,
// and a pointer to the mobile app for patients.
export function Dashboard() {
  const { user } = useAuth()

  if (user?.role === 'admin') {
    return (
      <Suspense fallback={<PageLoading />}>
        <AdminHome />
      </Suspense>
    )
  }
  if (user?.role === 'personnel') return <PersonnelHome />
  return <PatientWebNotice />
}
