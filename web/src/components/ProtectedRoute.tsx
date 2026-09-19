import type { ReactNode } from 'react'
import { Link, Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

interface ProtectedRouteProps {
  // Omit children to use as a layout route (renders <Outlet />).
  children?: ReactNode
  allowedRoles?: Array<'admin' | 'personnel' | 'patient'>
}

export function ProtectedRoute({ children, allowedRoles }: ProtectedRouteProps) {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return <div className="p-8 text-center">Loading...</div>
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  if (allowedRoles && !allowedRoles.includes(user.role)) {
    return <AccessDenied />
  }

  return children ? <>{children}</> : <Outlet />
}

// Rendered inside the shell, so the sidebar stays usable.
function AccessDenied() {
  return (
    <div className="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm">
      <h1 className="text-lg font-semibold text-gray-900">You don't have access to this page</h1>
      <p className="mt-2 text-sm text-slate-500">
        This area is limited to other roles. If you think you should have access, ask your clinic
        administrator.
      </p>
      <Link
        to="/dashboard"
        className="mt-6 inline-block rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
      >
        Go to Home
      </Link>
    </div>
  )
}
