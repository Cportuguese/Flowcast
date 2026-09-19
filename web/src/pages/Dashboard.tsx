import { PatientWebNotice } from '../components/PatientWebNotice'
import { useAuth } from '../context/AuthContext'
import { AdminHome } from './home/AdminHome'
import { PersonnelHome } from './home/PersonnelHome'

// One route, three homes: oversight for admin, queue-first for personnel,
// and a pointer to the mobile app for patients.
export function Dashboard() {
  const { user } = useAuth()

  if (user?.role === 'admin') return <AdminHome />
  if (user?.role === 'personnel') return <PersonnelHome />
  return <PatientWebNotice />
}
