import { PatientWebNotice } from '../components/PatientWebNotice'
import { useAuth } from '../context/AuthContext'
import { MySchedule } from './home/MySchedule'
import { NurseHome } from './home/NurseHome'

// One route, three homes: the head nurse's queue-first "Today", the
// dentist's and school physician's own schedule, and a pointer to the
// mobile app for patients.
export function Dashboard() {
  const { user } = useAuth()

  if (user?.role === 'admin') return <NurseHome />
  if (user?.role === 'personnel') return <MySchedule />
  return <PatientWebNotice />
}
