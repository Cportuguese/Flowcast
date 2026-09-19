import { PageHeader } from '../components/PageHeader'
import { useAuth } from '../context/AuthContext'

// Placeholder until the role-specific Home (Personnel "Today" / Admin "Overview")
// lands. Navigation now lives in the sidebar (AppShell).
export function Dashboard() {
  const { user } = useAuth()

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Home" subtitle={`Welcome, ${user?.name}`} />
    </div>
  )
}
