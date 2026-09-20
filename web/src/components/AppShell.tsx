import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import logo from '../assets/logo.png'
import { useAuth } from '../context/AuthContext'

interface NavItem {
  to: string
  label: string
}

// Visible to admin + personnel. Order matches the daily workflow.
const STAFF_NAV: NavItem[] = [
  { to: '/dashboard', label: 'Home' },
  { to: '/queue', label: 'Live Queue' },
  { to: '/appointments', label: 'Appointments' },
  { to: '/visits', label: 'Visit Records' },
  { to: '/inventory', label: 'Inventory' },
  { to: '/analytics', label: 'Analytics' },
]

// Appended under an "Admin" heading for admins only.
const ADMIN_NAV: NavItem[] = [
  { to: '/admin/settings', label: 'Clinic Settings' },
  { to: '/admin/reports', label: 'Reports' },
  { to: '/admin/queue-history', label: 'Queue History' },
  { to: '/admin/inventory/history', label: 'Inventory History' },
  { to: '/admin/audit-log', label: 'Audit Log' },
]

const linkBase = 'block rounded-md px-3 py-2 text-sm transition'
const linkIdle = 'text-slate-300 hover:bg-white/5 hover:text-white'
const linkActive = 'bg-white/10 font-semibold text-white'

function initials(name: string | undefined) {
  if (!name) return '?'
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')
}

export function AppShell() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)

  const isStaff = user?.role === 'admin' || user?.role === 'personnel'
  const isAdmin = user?.role === 'admin'

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  function closeDrawer() {
    setIsDrawerOpen(false)
  }

  const sidebar = (
    <div className="flex h-full flex-col bg-brand text-white">
      <div className="flex items-center gap-3 px-5 py-5">
        <span className="rounded-lg bg-white p-1">
          <img src={logo} alt="" className="h-7 w-7" />
        </span>
        <span className="text-base font-semibold tracking-tight">Flowcast</span>
      </div>

      <nav className="flex-1 space-y-6 overflow-y-auto px-3 pb-4">
        {isStaff && (
          <div className="space-y-0.5">
            {STAFF_NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/dashboard'}
                onClick={closeDrawer}
                className={({ isActive }) => `${linkBase} ${isActive ? linkActive : linkIdle}`}
              >
                {item.label}
              </NavLink>
            ))}
            <a
              href="/tv-board"
              target="_blank"
              rel="noopener noreferrer"
              className={`${linkBase} ${linkIdle}`}
            >
              TV Board ↗
            </a>
          </div>
        )}

        {isAdmin && (
          <div className="space-y-0.5">
            <p className="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
              Admin
            </p>
            {ADMIN_NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                onClick={closeDrawer}
                className={({ isActive }) => `${linkBase} ${isActive ? linkActive : linkIdle}`}
              >
                {item.label}
              </NavLink>
            ))}
          </div>
        )}
      </nav>

      <div className="border-t border-white/10 px-5 py-4">
        <div className="flex items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold">
            {initials(user?.name)}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium">{user?.name}</p>
            <p className="text-xs capitalize text-slate-400">{user?.role}</p>
          </div>
        </div>
        <button
          type="button"
          onClick={handleLogout}
          className="mt-3 text-xs text-slate-400 transition hover:text-white"
        >
          Log out
        </button>
      </div>
    </div>
  )

  return (
    <div className="min-h-screen bg-gray-50 lg:flex">
      {/* Desktop sidebar */}
      <aside className="hidden w-[232px] shrink-0 lg:fixed lg:inset-y-0 lg:block print:hidden">
        {sidebar}
      </aside>

      {/* Mobile top bar */}
      <header className="sticky top-0 z-30 flex items-center justify-between bg-brand px-4 py-3 text-white lg:hidden print:hidden">
        <div className="flex items-center gap-2">
          <span className="rounded-md bg-white p-0.5">
            <img src={logo} alt="" className="h-6 w-6" />
          </span>
          <span className="text-sm font-semibold">Flowcast</span>
        </div>
        <button
          type="button"
          onClick={() => setIsDrawerOpen((open) => !open)}
          aria-label={isDrawerOpen ? 'Close menu' : 'Open menu'}
          aria-expanded={isDrawerOpen}
          className="rounded-md p-1.5 hover:bg-white/10"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="h-5 w-5">
            {isDrawerOpen ? (
              <path d="M6 6l12 12M18 6L6 18" strokeLinecap="round" />
            ) : (
              <path d="M4 7h16M4 12h16M4 17h16" strokeLinecap="round" />
            )}
          </svg>
        </button>
      </header>

      {/* Mobile drawer */}
      {isDrawerOpen && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
          <button
            type="button"
            aria-label="Close menu"
            onClick={closeDrawer}
            className="absolute inset-0 bg-black/40"
          />
          <aside className="absolute inset-y-0 left-0 w-[232px] shadow-xl">{sidebar}</aside>
        </div>
      )}

      <main className="min-w-0 flex-1 p-6 lg:ml-[232px] lg:p-8 print:m-0 print:p-0">
        <Outlet />
      </main>
    </div>
  )
}
