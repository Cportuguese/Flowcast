import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react'

// Success confirmations only. Errors stay inline next to the thing that
// failed, where the user is already looking; a toast is for the actions
// that would otherwise give no visible feedback at all (stock received,
// schedule saved, no-show marked).

interface ToastState {
  id: number
  message: string
}

const ToastContext = createContext<((message: string) => void) | undefined>(undefined)

const DURATION_MS = 3500

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toast, setToast] = useState<ToastState | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const show = useCallback((message: string) => {
    if (timerRef.current) clearTimeout(timerRef.current)
    setToast({ id: Date.now(), message })
    timerRef.current = setTimeout(() => setToast(null), DURATION_MS)
  }, [])

  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [])

  return (
    <ToastContext.Provider value={show}>
      {children}
      {/* Always mounted so the live region exists before the first message. */}
      <div
        role="status"
        aria-live="polite"
        className="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex justify-center px-4 print:hidden"
      >
        {toast && (
          <div
            key={toast.id}
            className="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-lg"
          >
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth={2} className="h-4 w-4 text-green-400" aria-hidden="true">
              <path d="M5 10l3.5 3.5L15 7" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
            {toast.message}
          </div>
        )}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const show = useContext(ToastContext)
  if (!show) throw new Error('useToast must be used within ToastProvider')
  return show
}
