import type { ReactNode } from 'react'
import { PulseIcon } from '../components/icons'

export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen w-full flex-col bg-white md:flex-row">
      <div className="relative flex w-full flex-col justify-between overflow-hidden bg-[#0b1636] px-10 py-12 text-white md:w-[42%] md:px-14 md:py-16">
        <div className="pointer-events-none absolute -bottom-24 -right-20 h-72 w-72 rotate-12 rounded-[2.5rem] bg-blue-600/20" />
        <div className="pointer-events-none absolute -bottom-6 -right-6 h-48 w-48 rotate-12 rounded-[2rem] bg-blue-500/20" />

        <div className="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-600">
          <PulseIcon className="h-7 w-7" />
        </div>

        <div className="relative">
          <p className="text-sm font-semibold tracking-[0.2em] text-blue-300">CAMPUS HEALTH PORTAL</p>
          <h1 className="mt-3 text-4xl font-extrabold leading-tight">
            FLOW<span className="text-blue-400">CAST</span>
          </h1>
          <p className="mt-4 max-w-xs text-sm text-slate-300">
            Track your clinic visit, manage patient queues, and stay connected with campus
            health services.
          </p>
        </div>

        <p className="relative text-xs text-slate-400">
          &copy; {new Date().getFullYear()} Flowcast
        </p>
      </div>

      <div className="flex w-full flex-1 items-center justify-center px-6 py-12 md:px-16">
        <div className="w-full max-w-md">{children}</div>
      </div>
    </div>
  )
}
