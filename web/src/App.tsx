import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { PageLoading } from './components/PageLoading'
import { ProtectedRoute } from './components/ProtectedRoute'
import { Account } from './pages/Account'
import { AdminSettings } from './pages/AdminSettings'
import { Appointments } from './pages/Appointments'
import { AuditLog } from './pages/AuditLog'
import { Dashboard } from './pages/Dashboard'
import { Inventory } from './pages/Inventory'
import { InventoryHistory } from './pages/InventoryHistory'
import { Login } from './pages/Login'
import { QueueBoard } from './pages/QueueBoard'
import { QueueHistory } from './pages/QueueHistory'
import { Reports } from './pages/Reports'
import { TvBoard } from './pages/TvBoard'
import { VisitsSearch } from './pages/VisitsSearch'

// The only page besides AdminHome that needs recharts — split it so the
// nurse's first load (queue, appointments, visits) doesn't pay for charts.
const AnalyticsDashboard = lazy(() =>
  import('./pages/AnalyticsDashboard').then((m) => ({ default: m.AnalyticsDashboard })),
)

function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/tv-board" element={<TvBoard />} />

      {/* Everything below is signed-in and rendered inside the sidebar shell. */}
      <Route element={<ProtectedRoute />}>
        <Route element={<AppShell />}>
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/account" element={<Account />} />

          <Route element={<ProtectedRoute allowedRoles={['admin', 'personnel']} />}>
            <Route path="/queue" element={<QueueBoard />} />
            <Route path="/appointments" element={<Appointments />} />
            <Route path="/visits" element={<VisitsSearch />} />
            <Route path="/inventory" element={<Inventory />} />
            <Route
              path="/analytics"
              element={
                <Suspense fallback={<PageLoading />}>
                  <AnalyticsDashboard />
                </Suspense>
              }
            />
          </Route>

          <Route element={<ProtectedRoute allowedRoles={['admin']} />}>
            <Route path="/admin/settings" element={<AdminSettings />} />
            <Route path="/admin/reports" element={<Reports />} />
            <Route path="/admin/queue-history" element={<QueueHistory />} />
            <Route path="/admin/inventory/history" element={<InventoryHistory />} />
            <Route path="/admin/audit-log" element={<AuditLog />} />
          </Route>
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}

export default App
