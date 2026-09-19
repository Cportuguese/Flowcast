import { Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from './components/ProtectedRoute'
import { AdminSettings } from './pages/AdminSettings'
import { AnalyticsDashboard } from './pages/AnalyticsDashboard'
import { Appointments } from './pages/Appointments'
import { AuditLog } from './pages/AuditLog'
import { Dashboard } from './pages/Dashboard'
import { Login } from './pages/Login'
import { QueueBoard } from './pages/QueueBoard'
import { Reports } from './pages/Reports'
import { TvBoard } from './pages/TvBoard'
import { VisitsSearch } from './pages/VisitsSearch'

function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/tv-board" element={<TvBoard />} />
      <Route
        path="/dashboard"
        element={
          <ProtectedRoute>
            <Dashboard />
          </ProtectedRoute>
        }
      />
      <Route
        path="/queue"
        element={
          <ProtectedRoute allowedRoles={['admin', 'personnel']}>
            <QueueBoard />
          </ProtectedRoute>
        }
      />
      <Route
        path="/appointments"
        element={
          <ProtectedRoute allowedRoles={['admin', 'personnel']}>
            <Appointments />
          </ProtectedRoute>
        }
      />
      <Route
        path="/visits"
        element={
          <ProtectedRoute allowedRoles={['admin', 'personnel']}>
            <VisitsSearch />
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/settings"
        element={
          <ProtectedRoute allowedRoles={['admin']}>
            <AdminSettings />
          </ProtectedRoute>
        }
      />
      <Route
        path="/analytics"
        element={
          <ProtectedRoute allowedRoles={['admin', 'personnel']}>
            <AnalyticsDashboard />
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/reports"
        element={
          <ProtectedRoute allowedRoles={['admin']}>
            <Reports />
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/audit-log"
        element={
          <ProtectedRoute allowedRoles={['admin']}>
            <AuditLog />
          </ProtectedRoute>
        }
      />
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}

export default App
