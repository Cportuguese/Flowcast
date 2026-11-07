import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { ProtectedRoute } from './components/ProtectedRoute'
import { Dashboard } from './pages/Dashboard'
import { Login } from './pages/Login'

// Sign-in and each role's home load with the app; every other page is
// fetched the first time it is opened (AppShell shows "Loading…" beside the
// sidebar meanwhile), so the first screen is not held up by pages — and
// recharts, which only Analytics needs — that most visits never reach.
const Account = lazy(() => import('./pages/Account').then((m) => ({ default: m.Account })))
const AdminSettings = lazy(() => import('./pages/AdminSettings').then((m) => ({ default: m.AdminSettings })))
const AnalyticsDashboard = lazy(() => import('./pages/AnalyticsDashboard').then((m) => ({ default: m.AnalyticsDashboard })))
const Appointments = lazy(() => import('./pages/Appointments').then((m) => ({ default: m.Appointments })))
const AuditLog = lazy(() => import('./pages/AuditLog').then((m) => ({ default: m.AuditLog })))
const Inventory = lazy(() => import('./pages/Inventory').then((m) => ({ default: m.Inventory })))
const InventoryHistory = lazy(() => import('./pages/InventoryHistory').then((m) => ({ default: m.InventoryHistory })))
const QueueBoard = lazy(() => import('./pages/QueueBoard').then((m) => ({ default: m.QueueBoard })))
const QueueHistory = lazy(() => import('./pages/QueueHistory').then((m) => ({ default: m.QueueHistory })))
const Reports = lazy(() => import('./pages/Reports').then((m) => ({ default: m.Reports })))
const TvBoard = lazy(() => import('./pages/TvBoard').then((m) => ({ default: m.TvBoard })))
const VisitsSearch = lazy(() => import('./pages/VisitsSearch').then((m) => ({ default: m.VisitsSearch })))

function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        path="/tv-board"
        element={
          <Suspense fallback={null}>
            <TvBoard />
          </Suspense>
        }
      />

      {/* Everything below is signed-in and rendered inside the sidebar shell. */}
      <Route element={<ProtectedRoute />}>
        <Route element={<AppShell />}>
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/account" element={<Account />} />

          {/* Visit records are for all three professionals. */}
          <Route element={<ProtectedRoute allowedRoles={['admin', 'personnel']} />}>
            <Route path="/visits" element={<VisitsSearch />} />
          </Route>

          {/* The rest is the head nurse's: she runs the queue and the clinic. */}
          <Route element={<ProtectedRoute allowedRoles={['admin']} />}>
            <Route path="/queue" element={<QueueBoard />} />
            <Route path="/appointments" element={<Appointments />} />
            <Route path="/inventory" element={<Inventory />} />
            <Route path="/analytics" element={<AnalyticsDashboard />} />
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
