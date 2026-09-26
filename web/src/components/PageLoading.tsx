// Suspense fallback for lazy routes — same quiet text the pages use while
// they fetch, so a code-split chunk loading looks no different from data.
export function PageLoading() {
  return <p className="mt-6 text-slate-400">Loading…</p>
}
