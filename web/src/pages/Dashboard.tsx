import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export function Dashboard() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const isStaff = user?.role === 'admin' || user?.role === 'personnel'
  const isAdmin = user?.role === 'admin'

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen bg-gray-50 p-8">
      <div className="mx-auto max-w-2xl rounded-lg bg-white p-8 shadow">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-semibold text-gray-900">Dashboard</h1>
          <button
            type="button"
            onClick={handleLogout}
            className="rounded bg-gray-200 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-300"
          >
            Log out
          </button>
        </div>

        <p className="mt-4 text-gray-600">
          Welcome, {user?.name} ({user?.role})
        </p>

        {isStaff && (
          <div className="mt-6 flex flex-wrap gap-3">
            <button
              type="button"
              onClick={() => navigate('/queue')}
              className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
            >
              Open Live Queue
            </button>
            <button
              type="button"
              onClick={() => navigate('/appointments')}
              className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            >
              Appointments
            </button>
            <button
              type="button"
              onClick={() => navigate('/visits')}
              className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            >
              Visit Records
            </button>
            <a
              href="/tv-board"
              target="_blank"
              rel="noopener noreferrer"
              className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            >
              Open TV Board ↗
            </a>
            <button
              type="button"
              onClick={() => navigate('/inventory')}
              className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            >
              Inventory
            </button>
            <button
              type="button"
              onClick={() => navigate('/analytics')}
              className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            >
              Analytics
            </button>
            {isAdmin && (
              <button
                type="button"
                onClick={() => navigate('/admin/settings')}
                className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
              >
                Clinic Settings
              </button>
            )}
            {isAdmin && (
              <button
                type="button"
                onClick={() => navigate('/admin/reports')}
                className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
              >
                Reports
              </button>
            )}
            {isAdmin && (
              <button
                type="button"
                onClick={() => navigate('/admin/audit-log')}
                className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
              >
                Audit Log
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
