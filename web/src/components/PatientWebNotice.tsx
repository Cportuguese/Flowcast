import logo from '../assets/logo.png'

// Patients who sign in on the web have nothing to do here; point them to the app.
export function PatientWebNotice() {
  return (
    <div className="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm">
      <img src={logo} alt="Flowcast" className="mx-auto h-16 w-16" />
      <h1 className="mt-4 text-lg font-semibold text-gray-900">Flowcast for patients lives in the mobile app</h1>
      <p className="mt-2 text-sm text-slate-500">
        Check in to the queue, book appointments, and view your visit history from the Flowcast
        app on your phone. This website is for clinic staff.
      </p>
    </div>
  )
}
