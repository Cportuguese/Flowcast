import type { ReactNode } from 'react'
import { LogoMark } from '../components/Logo'

export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen w-full flex-col bg-white md:flex-row">
      <div className="flex w-full flex-col justify-between gap-10 bg-brand px-10 py-12 text-white md:w-[42%] md:px-14 md:py-16">
        <div className="flex items-center gap-3">
          <LogoMark tone="white" className="h-12 w-12" />
          <span className="text-2xl font-semibold">Flowcast</span>
        </div>

        <p className="max-w-xs text-sm text-slate-300">
          Track your clinic visit, manage patient queues, and stay connected with campus health
          services.
        </p>

        <p className="text-xs text-slate-400">&copy; {new Date().getFullYear()} Flowcast</p>
      </div>

      <div className="flex w-full flex-1 items-center justify-center px-6 py-12 md:px-16">
        <div className="w-full max-w-md">{children}</div>
      </div>
    </div>
  )
}
