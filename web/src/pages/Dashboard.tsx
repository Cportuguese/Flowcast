import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export function Dashboard() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

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
      </div>
    </div>
  )
}
