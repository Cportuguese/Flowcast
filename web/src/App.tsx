import { Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { ProtectedRoute } from './components/ProtectedRoute'
import { AdminSettings } from './pages/AdminSettings'
import { AnalyticsDashboard } from './pages/AnalyticsDashboard'
import { Appointments } from './pages/Appointments'
import { AuditLog } from './pages/AuditLog'
import { Dashboard } from './pages/Dashboard'
import { Inventory } from './pages/Inventory'
import { InventoryHistory } from './pages/InventoryHistory'
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

      {/* Everything below is signed-in and rendered inside the sidebar shell. */}
      <Route element={<ProtectedRoute />}>
        <Route element={<AppShell />}>
          <Route path="/dashboard" element={<Dashboard />} />

          <Route element={<ProtectedRoute allowedRoles={['admin', 'personnel']} />}>
            <Route path="/queue" element={<QueueBoard />} />
            <Route path="/appointments" element={<Appointments />} />
            <Route path="/visits" element={<VisitsSearch />} />
            <Route path="/inventory" element={<Inventory />} />
            <Route path="/analytics" element={<AnalyticsDashboard />} />
          </Route>

          <Route element={<ProtectedRoute allowedRoles={['admin']} />}>
            <Route path="/admin/settings" element={<AdminSettings />} />
            <Route path="/admin/reports" element={<Reports />} />
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
